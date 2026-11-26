<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\ReadModel;

use Endurance\Workout\Step;

final readonly class WorkoutContentNode
{
    public function __construct(
        public int $ordinal,
        public ?int $parent,
        public Step|RepeatInstruction $instruction,
    ) {
        if ($ordinal < 1 || (null !== $parent && ($parent < 1 || $parent >= $ordinal))) {
            throw new \InvalidArgumentException('Invalid Workout node position.');
        }
    }
}
