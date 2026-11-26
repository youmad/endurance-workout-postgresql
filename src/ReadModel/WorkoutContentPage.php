<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\ReadModel;

use Endurance\Foundation\ValueObject\Instant;
use Endurance\Workout\ValueObject\WorkoutRevisionReference;

final readonly class WorkoutContentPage
{
    /** @param list<WorkoutContentNode> $nodes */
    public function __construct(
        public WorkoutRevisionReference $reference,
        public Instant $publishedAt,
        public ?string $name,
        public ?string $description,
        public int $occurrenceCount,
        public array $nodes,
        public ?int $nextAfter,
    ) {
    }
}
