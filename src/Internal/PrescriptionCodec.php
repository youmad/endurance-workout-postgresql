<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Internal;

use Endurance\Workout\Duration\DistanceDuration;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Duration\TimeDuration;
use Endurance\Workout\Repeat;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\StepPurpose;
use Endurance\Workout\Target\FreeTarget;
use Endurance\Workout\Target\TargetMetric;
use Endurance\Workout\Target\TargetRange;
use Endurance\Workout\Workout;
use Endurance\WorkoutPostgresql\ReadModel\RepeatInstruction;
use Endurance\WorkoutPostgresql\ReadModel\WorkoutContentNode;

/** Versioned flat parent-index tree. No recursion or expansion; float bits are independent of JSON precision. @internal */
final readonly class PrescriptionCodec
{
    public const string FORMAT = 'workout-prescription.v1';
    public const int MAX_NODES = 65534;
    public const int MAX_BYTES = 16777216;

    /** @return array{json:string,nodes:int,occurrences:int} */
    public function encode(Workout $workout): array
    {
        $nodes = [];
        $stack = [[$workout->steps, 0, null]];
        while ([] !== $stack) {
            $last = count($stack) - 1;
            [$sequence, $offset, $parent] = $stack[$last];
            if ($offset === count($sequence->nodes)) {
                array_pop($stack);
                continue;
            }
            if (count($nodes) >= self::MAX_NODES) {
                throw new \InvalidArgumentException('Stored Workout exceeds the source node limit.');
            }
            ++$stack[$last][1];
            $node = $sequence->nodes[$offset];
            $index = count($nodes);
            if ($node instanceof Repeat) {
                $nodes[] = ['kind' => 'repeat', 'parent' => $parent, 'count' => $node->repetitions];
                $stack[] = [$node->steps, 0, $index];
            } else {
                $duration = match (true) {
                    $node->duration instanceof TimeDuration => ['kind' => 'time', 'microseconds' => $node->duration->microseconds],
                    $node->duration instanceof DistanceDuration => ['kind' => 'distance', 'metres' => $this->bits($node->duration->metres)],
                    default => ['kind' => 'open'],
                };
                $target = $node->target instanceof FreeTarget ? ['kind' => 'free']
                    : ['kind' => 'range', 'metric' => $node->target->metric->value,
                        'minimum' => $this->bits($node->target->minimum), 'maximum' => $this->bits($node->target->maximum)];
                $nodes[] = ['kind' => 'step', 'parent' => $parent, 'duration' => $duration, 'target' => $target,
                    'purpose' => $node->purpose->value, 'name' => $node->name, 'instructions' => $node->instructions];
            }
        }
        $json = json_encode(['format' => self::FORMAT, 'name' => $workout->name, 'description' => $workout->description,
            'nodes' => $nodes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Stored Workout exceeds the encoded byte limit.');
        }

        return ['json' => $json, 'nodes' => count($nodes), 'occurrences' => $workout->steps->occurrenceCount];
    }

    public function decode(string $json, int $expectedNodes, int $expectedOccurrences): Workout
    {
        try {
            if (strlen($json) > self::MAX_BYTES || $expectedNodes < 1 || $expectedNodes > self::MAX_NODES || $expectedOccurrences < 1) {
                throw new \UnexpectedValueException('Invalid stored Workout bounds.');
            }
            $data = $this->object(json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), ['format', 'name', 'description', 'nodes']);
            if (self::FORMAT !== $data['format'] || !is_array($data['nodes']) || !array_is_list($data['nodes']) || count($data['nodes']) !== $expectedNodes) {
                throw new \UnexpectedValueException('Invalid stored Workout format/node count.');
            }
            /** @var array<int,list<Step|Repeat>> $children */
            $children = [];
            for ($index = $expectedNodes - 1; $index >= 0; --$index) {
                $raw = $this->node($data['nodes'][$index], $index);
                $parent = $raw['parent'];
                if ('repeat' === $raw['kind']) {
                    $node = new Repeat($raw['count'], new Sequence(...array_reverse($children[$index] ?? [])));
                } else {
                    if (isset($children[$index])) {
                        throw new \UnexpectedValueException('Stored Step cannot own children.');
                    }
                    $node = $this->step($raw);
                }
                unset($children[$index]);
                $children[$parent ?? -1][] = $node;
            }
            $workout = new Workout(new Sequence(...array_reverse($children[-1] ?? [])), $this->text($data['name']), $this->text($data['description']));
            if ($workout->steps->occurrenceCount !== $expectedOccurrences) {
                throw new \UnexpectedValueException('Stored Workout occurrence count changed.');
            }

            return $workout;
        } catch (\JsonException|\InvalidArgumentException|\ValueError|\TypeError $failure) {
            throw new \UnexpectedValueException('Invalid stored Workout prescription.', previous: $failure);
        }
    }

    /** @return array{name:?string,description:?string,nodes:list<WorkoutContentNode>,nextAfter:?int} */
    public function decodePage(string $header, string $json, int $expectedNodes, int $expectedOccurrences, int $limit, int $after): array
    {
        try {
            if ($expectedNodes < 1 || $expectedNodes > self::MAX_NODES || $expectedOccurrences < 1) {
                throw new \UnexpectedValueException('Invalid stored Workout bounds.');
            }
            $data = $this->object(json_decode($header, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), ['format', 'name', 'description']);
            $rawNodes = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (self::FORMAT !== $data['format'] || !is_array($rawNodes) || !array_is_list($rawNodes)
                || count($rawNodes) !== max(0, min($limit, $expectedNodes - $after))) {
                throw new \UnexpectedValueException('Invalid stored Workout page format/node count.');
            }
            $nodes = [];
            foreach ($rawNodes as $offset => $value) {
                $index = $after + $offset;
                $raw = $this->node($value, $index);
                $parent = null === $raw['parent'] ? null : $raw['parent'] + 1;
                $nodes[] = new WorkoutContentNode($index + 1, $parent,
                    'repeat' === $raw['kind'] ? new RepeatInstruction($raw['count']) : $this->step($raw));
            }

            return ['name' => $this->text($data['name']), 'description' => $this->text($data['description']),
                'nodes' => $nodes, 'nextAfter' => $after + count($nodes) < $expectedNodes ? $after + count($nodes) : null];
        } catch (\JsonException|\InvalidArgumentException|\ValueError|\TypeError $failure) {
            throw new \UnexpectedValueException('Invalid stored Workout content page.', previous: $failure);
        }
    }

    /** @return array<string,mixed> */
    private function node(mixed $value, int $index): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid stored Workout node.');
        }
        $raw = $this->object($value, match ($value['kind'] ?? null) {
            'repeat' => ['kind', 'parent', 'count'],
            'step' => ['kind', 'parent', 'duration', 'target', 'purpose', 'name', 'instructions'],
            default => throw new \UnexpectedValueException('Unknown stored Workout node.'),
        });
        if (null !== $raw['parent'] && (!is_int($raw['parent']) || $raw['parent'] < 0 || $raw['parent'] >= $index)) {
            throw new \UnexpectedValueException('Invalid stored Workout parent reference.');
        }
        if ('repeat' === $raw['kind']) {
            $raw['count'] = $this->integer($raw['count']);
        }

        return $raw;
    }

    /** @param array<string,mixed> $data */
    private function step(array $data): Step
    {
        $duration = $data['duration'];
        if (!is_array($duration)) {
            throw new \UnexpectedValueException('Invalid stored Workout duration.');
        }
        $duration = match ($duration['kind'] ?? null) {
            'time' => $this->object($duration, ['kind', 'microseconds']),
            'distance' => $this->object($duration, ['kind', 'metres']),
            'open' => $this->object($duration, ['kind']),
            default => throw new \UnexpectedValueException('Unknown stored Workout duration.'),
        };
        $ending = match ($duration['kind']) {
            'time' => new TimeDuration($this->integer($duration['microseconds'])),
            'distance' => new DistanceDuration($this->number($duration['metres'])),
            default => new OpenDuration(),
        };
        $target = $data['target'];
        if (!is_array($target)) {
            throw new \UnexpectedValueException('Invalid stored Workout target.');
        }
        $target = match ($target['kind'] ?? null) {
            'free' => $this->object($target, ['kind']),
            'range' => $this->object($target, ['kind', 'metric', 'minimum', 'maximum']),
            default => throw new \UnexpectedValueException('Unknown stored Workout target.'),
        };
        $range = 'free' === $target['kind'] ? new FreeTarget()
            : new TargetRange(TargetMetric::from($target['metric']), $this->number($target['minimum']), $this->number($target['maximum']));

        return new Step($ending, $range, StepPurpose::from($data['purpose']), $this->text($data['name']), $this->text($data['instructions']));
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string,mixed>
     */
    private function object(mixed $value, array $keys): array
    {
        if (!is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value))) {
            throw new \UnexpectedValueException('Stored Workout object has an invalid shape.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new \UnexpectedValueException('Stored Workout count/duration must be a positive integer.');
        }

        return $value;
    }

    private function text(mixed $value): ?string
    {
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException('Invalid stored Workout text.');
        }

        return $value;
    }

    private function bits(float $value): string
    {
        return bin2hex(pack('E', $value));
    }

    private function number(mixed $value): float
    {
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{16}\z/D', $value)) {
            throw new \UnexpectedValueException('Invalid stored Workout float encoding.');
        }
        $decoded = unpack('Evalue', hex2bin($value));
        if (false === $decoded || !is_finite($decoded['value'])) {
            throw new \UnexpectedValueException('Invalid stored Workout float.');
        }

        return $decoded['value'];
    }
}
