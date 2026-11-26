<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Tests\Unit;

use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\Workout;
use Endurance\WorkoutPostgresql\Internal\PrescriptionCodec;
use Endurance\WorkoutPostgresql\WorkoutReadProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkoutReadProjectionTest extends TestCase
{
    #[DataProvider('invalidQueries')]
    public function testUnboundedOrInjectedQueriesAreRejected(string $alias, int $limit, int $after): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkoutReadProjection::contentColumns($alias, $limit, $after);
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function invalidQueries(): iterable
    {
        yield 'empty page' => ['r', 0, 0];
        yield 'oversized page' => ['r', 101, 0];
        yield 'negative cursor' => ['r', 1, -1];
        yield 'oversized cursor' => ['r', 1, 65535];
        yield 'SQL alias' => ['r; SELECT 1', 1, 0];
    }

    #[DataProvider('corruptPages')]
    public function testUnknownHeadersAndCorruptSelectedFactsAreNotSuccessfulEmptyPages(string $case): void
    {
        $codec = new PrescriptionCodec();
        $encoded = $codec->encode(new Workout(new Sequence(new Step(new OpenDuration()))));
        $data = json_decode($encoded['json'], true, flags: JSON_THROW_ON_ERROR);
        $nodes = $data['nodes'];
        unset($data['nodes']);
        switch ($case) {
            case 'format': $data['format'] = 'future.v2';
                break;
            case 'missing text': unset($data['description']);
                break;
            case 'short slice': $nodes = [];
                break;
            case 'extra slice': $nodes[] = $nodes[0];
                break;
            case 'self parent': $nodes[0]['parent'] = 0;
                break;
            case 'unknown instruction': $nodes[0]['kind'] = 'future';
                break;
            default: throw new \LogicException('Unknown page fixture.');
        }
        $this->expectException(\UnexpectedValueException::class);
        $codec->decodePage(json_encode($data, JSON_THROW_ON_ERROR), json_encode($nodes, JSON_THROW_ON_ERROR), 1, 1, 1, 0);
    }

    /** @return iterable<string,array{string}> */
    public static function corruptPages(): iterable
    {
        foreach (['format', 'missing text', 'short slice', 'extra slice', 'self parent', 'unknown instruction'] as $case) {
            yield $case => [$case];
        }
    }
}
