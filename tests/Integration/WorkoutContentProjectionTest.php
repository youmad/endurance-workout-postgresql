<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Tests\Integration;

use Endurance\Workout\Duration\DistanceDuration;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Duration\TimeDuration;
use Endurance\Workout\Repeat;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\StepPurpose;
use Endurance\Workout\Target\TargetMetric;
use Endurance\Workout\Target\TargetRange;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\Workout\ValueObject\WorkoutRevisionReference;
use Endurance\Workout\Workout;
use Endurance\WorkoutPostgresql\ReadModel\RepeatInstruction;
use Endurance\WorkoutPostgresql\ReadModel\WorkoutContentPage;
use Endurance\WorkoutPostgresql\Tests\Support\StorageTestCase;
use Endurance\WorkoutPostgresql\WorkoutReadProjection;

final class WorkoutContentProjectionTest extends StorageTestCase
{
    public function testPagesKeepNestedParentsExactValuesAndTheRequestedHistoricalRevision(): void
    {
        $distance = new Step(new DistanceDuration(5e-324), new TargetRange(TargetMetric::PowerPercentFtp, -0.0, 123.45678901234567), StepPurpose::Active, 'Работа', "<plain>\ntext");
        $plan = $this->publisher()->create(WorkoutId::generate(), WorkoutRevisionId::generate(), new Workout(new Sequence(
            new Repeat(3, new Sequence(new Step(new TimeDuration(PHP_INT_MAX), name: '', instructions: '0'), new Repeat(1, new Sequence($distance)))),
            new Step(new OpenDuration()),
        ), '', null));
        $this->publisher()->revise($plan->id, $plan->expectation(), WorkoutRevisionId::generate(), $this->prescription('New head'));
        $nodes = [];
        $after = 0;
        do {
            $page = $this->page($plan->reference(), 2, $after);
            self::assertNotNull($page);
            self::assertTrue($plan->reference()->equals($page->reference));
            self::assertTrue($plan->updatedAt->equals($page->publishedAt));
            self::assertSame('', $page->name);
            self::assertNull($page->description);
            self::assertSame(7, $page->occurrenceCount);
            $nodes = [...$nodes, ...$page->nodes];
            $after = $page->nextAfter;
        } while (null !== $after);
        self::assertSame([1, 2, 3, 4, 5], array_column($nodes, 'ordinal'));
        self::assertSame([null, 1, 1, 3, null], array_column($nodes, 'parent'));
        self::assertSame([3, null, 1, null, null], array_map(static fn ($node): ?int => $node->instruction instanceof RepeatInstruction ? $node->instruction->repetitions : null, $nodes));
        self::assertSame(PHP_INT_MAX, $nodes[1]->instruction->duration->microseconds);
        self::assertSame('', $nodes[1]->instruction->name);
        self::assertSame('0', $nodes[1]->instruction->instructions);
        self::assertEquals($distance, $nodes[3]->instruction);
        self::assertSame(pack('E', 5e-324), pack('E', $nodes[3]->instruction->duration->metres));
        self::assertSame(pack('E', -0.0), pack('E', $nodes[3]->instruction->target->minimum));
        self::assertSame(pack('E', 123.45678901234567), pack('E', $nodes[3]->instruction->target->maximum));
        $empty = $this->page($plan->reference(), 2, 65534);
        self::assertNotNull($empty);
        self::assertSame([], $empty->nodes);
        self::assertNull($empty->nextAfter);
        self::assertTrue($plan->reference()->equals($empty->reference));
        self::assertNull($this->page(new WorkoutRevisionReference(WorkoutId::generate(), $plan->currentRevisionId)));
    }

    public function testMaximumRepetitionsAreCountsWithoutRetainedSubtreesOrExpansion(): void
    {
        $plan = $this->publisher()->create(WorkoutId::generate(), WorkoutRevisionId::generate(), new Workout(new Sequence(
            new Repeat(PHP_INT_MAX, new Sequence(new Step(new OpenDuration()))),
        )));
        $page = $this->page($plan->reference(), 1);
        self::assertNotNull($page);
        self::assertCount(1, $page->nodes);
        self::assertSame(PHP_INT_MAX, $page->occurrenceCount);
        self::assertInstanceOf(RepeatInstruction::class, $page->nodes[0]->instruction);
        self::assertSame(PHP_INT_MAX, $page->nodes[0]->instruction->repetitions);
        self::assertSame(1, $page->nextAfter);
    }

    public function testSourceSizeDoesNotDetermineTheTransferredPageOrRequireAncestorHydration(): void
    {
        $node = new Step(new OpenDuration(), name: 'Leaf');
        for ($i = 0; $i < 1500; ++$i) {
            $node = new Repeat(1, new Sequence($node));
        }
        $plan = $this->publisher()->create(WorkoutId::generate(), WorkoutRevisionId::generate(), new Workout(new Sequence(
            $node, ...array_fill(0, 65534 - 1501, new Step(new TimeDuration(1), instructions: str_repeat('x', 32))),
        ), 'Large'));
        $row = $this->row($plan->reference(), 1, 1500);
        self::assertIsArray($row);
        self::assertArrayNotHasKey('prescription', $row);
        self::assertLessThan(500, strlen($row['content_nodes']));
        self::assertLessThan(150, strlen($row['content_header']));
        self::assertGreaterThan(8000000, (int) $this->db->fetchOne('SELECT octet_length(prescription) FROM workout_revisions WHERE id=?', [$plan->currentRevisionId->toString()]));
        $page = WorkoutReadProjection::contentPage($row, 1, 1500);
        self::assertSame(1501, $page->nodes[0]->ordinal);
        self::assertSame(1500, $page->nodes[0]->parent);
        self::assertSame('Leaf', $page->nodes[0]->instruction->name);
        self::assertSame(1501, $page->nextAfter);
        $last = $this->page($plan->reference(), 100, 65533);
        self::assertNotNull($last);
        self::assertCount(1, $last->nodes);
        self::assertSame(65534, $last->nodes[0]->ordinal);
        self::assertNull($last->nextAfter);
    }

    private function page(WorkoutRevisionReference $reference, int $limit = 50, int $after = 0): ?WorkoutContentPage
    {
        $row = $this->row($reference, $limit, $after);

        return false === $row ? null : WorkoutReadProjection::contentPage($row, $limit, $after);
    }

    /** @return array<string,mixed>|false */
    private function row(WorkoutRevisionReference $reference, int $limit, int $after): array|false
    {
        $columns = WorkoutReadProjection::contentColumns('r', $limit, $after);

        return $this->db->fetchAssociative('SELECT '.$columns.' FROM workout_revisions r WHERE r.workout_id=? AND r.id=?',
            [$reference->workoutId->toString(), $reference->revisionId->toString()]);
    }
}
