<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\ReadModel;

final readonly class RepeatInstruction
{
    public function __construct(public int $repetitions)
    {
        if ($repetitions < 1) {
            throw new \InvalidArgumentException('Workout repeat count must be positive.');
        }
    }
}
