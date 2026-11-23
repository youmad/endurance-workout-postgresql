<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Endurance\Foundation\Clock\FrozenClock;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Workout\Application\UseCase\PublishWorkout;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Duration\TimeDuration;
use Endurance\Workout\Entity\WorkoutPlan;
use Endurance\Workout\Repeat;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\Workout\Workout;
use Endurance\WorkoutPostgresql\DoctrineDbalWorkoutRepository;
use PHPUnit\Framework\TestCase;

abstract class StorageTestCase extends TestCase
{
    protected Connection $db;
    protected DoctrineDbalWorkoutRepository $storage;
    protected FrozenClock $clock;

    public static function connect(): Connection
    {
        return DriverManager::getConnection(require dirname(__DIR__).'/connection.php');
    }

    protected function setUp(): void
    {
        $this->db = self::connect();
        $this->db->executeStatement('TRUNCATE workout_plans, workout_revisions CASCADE');
        $this->storage = new DoctrineDbalWorkoutRepository($this->db);
        $this->clock = new FrozenClock($this->instant('2026-11-07T12:00:00.123456+05:00'));
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->db->close();
    }

    protected function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(new \DateTimeImmutable($value));
    }

    protected function prescription(string $name = 'План'): Workout
    {
        return new Workout(new Sequence(new Step(new TimeDuration(1000000), name: 'Start'),
            new Repeat(3, new Sequence(new Step(new OpenDuration(), name: 'Work')))), $name, 'Keep the original instructions');
    }

    protected function publisher(?Connection $connection = null): PublishWorkout
    {
        return new PublishWorkout(null === $connection ? $this->storage : new DoctrineDbalWorkoutRepository($connection), $this->clock);
    }

    protected function create(): WorkoutPlan
    {
        return $this->publisher()->create(WorkoutId::generate(), WorkoutRevisionId::generate(), $this->prescription());
    }
}
