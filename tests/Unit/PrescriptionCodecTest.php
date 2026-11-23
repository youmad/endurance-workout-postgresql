<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Tests\Unit;

use Endurance\Workout\Duration\DistanceDuration;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Duration\TimeDuration;
use Endurance\Workout\Repeat;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\StepPurpose;
use Endurance\Workout\Target\TargetMetric;
use Endurance\Workout\Target\TargetRange;
use Endurance\Workout\Workout;
use Endurance\WorkoutPostgresql\Internal\PrescriptionCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrescriptionCodecTest extends TestCase
{
    private function prescription(): Workout
    {
        return new Workout(new Sequence(new Step(new TimeDuration(PHP_INT_MAX), purpose: StepPurpose::WarmUp, name: '', instructions: '0'),
            new Repeat(3, new Sequence(new Step(new DistanceDuration(123.45678901234567), new TargetRange(TargetMetric::PowerPercentFtp, 105, 120), StepPurpose::Active, 'Работа'),
                new Repeat(1, new Sequence(new Step(new OpenDuration(), purpose: StepPurpose::Recovery)))))), 'План', "Notes\nwith <plain> text");
    }

    public function testMixedPrescriptionsRetainAllValuesOrderAndCountOneBlocks(): void
    {
        $codec = new PrescriptionCodec();
        $original = $this->prescription();
        $encoded = $codec->encode($original);
        $decoded = $codec->decode($encoded['json'], $encoded['nodes'], $encoded['occurrences']);
        self::assertSame($encoded, $codec->encode($decoded));
        self::assertSame(7, $decoded->steps->occurrenceCount);
        self::assertSame('Работа', $decoded->steps->stepAt(6)->name);
        self::assertSame('0', $decoded->steps->stepAt(1)->instructions);
        self::assertSame('', $decoded->steps->stepAt(1)->name);
    }

    public function testExactFloatBitsSurviveLowJsonPrecisionIncludingSignedZero(): void
    {
        $precision = ini_set('precision', '3');
        $serialize = ini_set('serialize_precision', '3');
        try {
            $codec = new PrescriptionCodec();
            foreach (TargetMetric::cases() as $metric) {
                $value = new Workout(new Sequence(new Step(new DistanceDuration(5e-324), new TargetRange($metric, -0.0, 123.45678901234567))));
                $encoded = $codec->encode($value);
                $decoded = $codec->decode($encoded['json'], $encoded['nodes'], $encoded['occurrences'])->steps->stepAt(1);
                self::assertInstanceOf(DistanceDuration::class, $decoded->duration);
                self::assertSame(pack('E', 5e-324), pack('E', $decoded->duration->metres));
                self::assertInstanceOf(TargetRange::class, $decoded->target);
                self::assertSame($metric, $decoded->target->metric);
                self::assertSame(pack('E', -0.0), pack('E', $decoded->target->minimum));
                self::assertSame(pack('E', 123.45678901234567), pack('E', $decoded->target->maximum));
            }
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serialize);
        }
    }

    public function testMaximumRepeatCountsNeverExpand(): void
    {
        $codec = new PrescriptionCodec();
        $value = new Workout(new Sequence(new Repeat(PHP_INT_MAX, new Sequence(new Step(new OpenDuration())))));
        $encoded = $codec->encode($value);
        self::assertSame(2, $encoded['nodes']);
        self::assertLessThan(500, strlen($encoded['json']));
        $decoded = $codec->decode($encoded['json'], 2, PHP_INT_MAX);
        self::assertCount(2, iterator_to_array($decoded->steps->occurrences(PHP_INT_MAX - 2, 10)));
    }

    public function testDeepTreesUseFlatStorageWithoutTheJsonNestingLimit(): void
    {
        $node = new Step(new OpenDuration());
        for ($i = 0; $i < 1500; ++$i) {
            $node = new Repeat(1, new Sequence($node));
        }
        $codec = new PrescriptionCodec();
        $encoded = $codec->encode(new Workout(new Sequence($node)));
        self::assertSame(1501, $encoded['nodes']);
        $decoded = $codec->decode($encoded['json'], 1501, 1);
        self::assertInstanceOf(OpenDuration::class, $decoded->steps->stepAt(1)->duration);
        self::assertSame($encoded, $codec->encode($decoded));
    }

    #[DataProvider('invalidPayloads')]
    public function testCorruptOrUnknownStoredFactsAreRejected(string $case): void
    {
        $codec = new PrescriptionCodec();
        $encoded = $codec->encode(new Workout(new Sequence(new Step(new TimeDuration(1)))));
        $data = json_decode($encoded['json'], true, flags: JSON_THROW_ON_ERROR);
        $count = $occurrences = 1;
        switch ($case) {
            case 'format': $data['format'] = 'future.v9';
                break;
            case 'unknown field': $data['extra'] = true;
                break;
            case 'missing text': unset($data['name']);
                break;
            case 'text type': $data['description'] = 1;
                break;
            case 'wrong node count': $count = 2;
                break;
            case 'wrong occurrence count': $occurrences = 2;
                break;
            case 'parent forward': $data['nodes'][0]['parent'] = 1;
                break;
            case 'parent self': $data['nodes'][0]['parent'] = 0;
                break;
            case 'parent negative': $data['nodes'][0]['parent'] = -1;
                break;
            case 'unknown node': $data['nodes'][0]['kind'] = 'unknown';
                break;
            case 'unknown purpose': $data['nodes'][0]['purpose'] = 'other';
                break;
            case 'duration float': $data['nodes'][0]['duration']['microseconds'] = 0.5;
                break;
            case 'duration string': $data['nodes'][0]['duration']['microseconds'] = '1';
                break;
            case 'duration zero': $data['nodes'][0]['duration']['microseconds'] = 0;
                break;
            case 'duration missing': $data['nodes'][0]['duration'] = ['kind' => 'time'];
                break;
            case 'duration unknown': $data['nodes'][0]['duration'] = ['kind' => 'other'];
                break;
            case 'unknown target': $data['nodes'][0]['target'] = ['kind' => 'unknown'];
                break;
            case 'unknown metric': $data['nodes'][0]['target'] = ['kind' => 'range', 'metric' => 'unknown', 'minimum' => '0000000000000000', 'maximum' => '0000000000000000'];
                break;
            case 'invalid float': $data['nodes'][0]['duration'] = ['kind' => 'distance', 'metres' => 'NaN'];
                break;
            case 'infinite float': $data['nodes'][0]['duration'] = ['kind' => 'distance', 'metres' => '7ff0000000000000'];
                break;
            case 'empty repeat': $data['nodes'][0] = ['kind' => 'repeat', 'parent' => null, 'count' => 1];
                break;
            case 'Step children': $data['nodes'][] = $data['nodes'][0];
                $data['nodes'][1]['parent'] = 0;
                $count = $occurrences = 2;
                break;
            case 'repeat zero': $data['nodes'][0] = ['kind' => 'repeat', 'parent' => null, 'count' => 0];
                break;
            default: throw new \LogicException('Unknown codec fixture.');
        }
        $this->expectException(\UnexpectedValueException::class);
        $codec->decode(json_encode($data, JSON_THROW_ON_ERROR), $count, $occurrences);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['format', 'unknown field', 'missing text', 'text type', 'wrong node count', 'wrong occurrence count', 'parent forward', 'parent self', 'parent negative', 'unknown node', 'unknown purpose', 'duration float', 'duration string', 'duration zero', 'duration missing', 'duration unknown', 'unknown target', 'unknown metric', 'invalid float', 'infinite float', 'empty repeat', 'Step children', 'repeat zero'] as $case) {
            yield $case => [$case];
        }
    }

    public function testEncodedTextHasAHardByteBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrescriptionCodec()->encode(new Workout(new Sequence(new Step(new OpenDuration())), str_repeat('x', PrescriptionCodec::MAX_BYTES)));
    }

    public function testPhysicalSourceNodesHaveAHardBoundEvenForSharedSubtrees(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrescriptionCodec()->encode(new Workout(new Sequence(...array_fill(0, PrescriptionCodec::MAX_NODES + 1, new Step(new OpenDuration())))));
    }
}
