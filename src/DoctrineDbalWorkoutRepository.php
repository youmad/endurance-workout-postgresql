<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Workout\Application\Port\WorkoutRepository;
use Endurance\Workout\Entity\WorkoutPlan;
use Endurance\Workout\Entity\WorkoutRevision;
use Endurance\Workout\Exception\WorkoutAlreadyExists;
use Endurance\Workout\Exception\WorkoutNotFound;
use Endurance\Workout\Exception\WorkoutRevisionAlreadyExists;
use Endurance\Workout\Exception\WorkoutRevisionNotFound;
use Endurance\Workout\ValueObject\ExpectedWorkoutState;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\Workout\ValueObject\WorkoutRevisionReference;
use Endurance\Workout\ValueObject\WorkoutVersion;
use Endurance\WorkoutPostgresql\Internal\PrescriptionCodec;

/** Unscoped storage; callers must authorize every operation. */
final readonly class DoctrineDbalWorkoutRepository implements WorkoutRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(WorkoutId $id): WorkoutPlan
    {
        return $this->readPlan($id, false);
    }

    public function getRevision(WorkoutRevisionReference $reference): WorkoutRevision
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workout_revisions WHERE id=? AND workout_id=?',
            [$reference->revisionId->toString(), $reference->workoutId->toString()]);
        if (false === $row) {
            throw new WorkoutRevisionNotFound('Workout revision not found.');
        }
        $prescription = new PrescriptionCodec()->decode($row['prescription'], (int) $row['node_count'], (int) $row['occurrence_count']);

        return new WorkoutRevision($reference->revisionId, $reference->workoutId, $prescription, $this->instant($row['created_at']));
    }

    public function publishNew(WorkoutRevision $revision): WorkoutPlan
    {
        return $this->publish($revision, null);
    }

    public function publishReplacement(WorkoutRevision $revision, ExpectedWorkoutState $expected): WorkoutPlan
    {
        return $this->publish($revision, $expected);
    }

    private function publish(WorkoutRevision $revision, ?ExpectedWorkoutState $expected): WorkoutPlan
    {
        $payload = new PrescriptionCodec()->encode($revision->prescription);

        return $this->transaction(function () use ($revision, $expected, $payload): WorkoutPlan {
            $plan = null === $expected ? WorkoutPlan::create($revision)
                : $this->readPlan($revision->workoutId, true)->withRevision($revision, $expected);
            try {
                $reserved = $this->connection->fetchOne('INSERT INTO workout_revisions(id,workout_id,plan_version,created_at,prescription,node_count,occurrence_count)
                    VALUES (?,?,?,?,?,?,?) ON CONFLICT (id) DO NOTHING RETURNING id',
                    [$revision->id->toString(), $revision->workoutId->toString(), $plan->version->value, $this->time($revision->createdAt),
                        $payload['json'], $payload['nodes'], $payload['occurrences']]);
            } catch (UniqueConstraintViolationException $failure) {
                if (null !== $expected) {
                    throw $failure;
                }
                throw new WorkoutAlreadyExists('Workout already exists.', previous: $failure);
            }
            if (false === $reserved) {
                throw new WorkoutRevisionAlreadyExists('Workout revision identity is already reserved.');
            }
            if (null === $expected) {
                try {
                    $this->connection->insert('workout_plans', ['id' => $plan->id->toString(), 'current_revision_id' => $plan->currentRevisionId->toString(),
                        'version' => $plan->version->value, 'created_at' => $this->time($plan->createdAt), 'updated_at' => $this->time($plan->updatedAt)]);
                } catch (UniqueConstraintViolationException $failure) {
                    throw new WorkoutAlreadyExists('Workout already exists.', previous: $failure);
                }
            } else {
                $this->connection->executeStatement('UPDATE workout_plans SET current_revision_id=?,version=?,updated_at=? WHERE id=?',
                    [$plan->currentRevisionId->toString(), $plan->version->value, $this->time($plan->updatedAt), $plan->id->toString()]);
            }

            return $plan;
        });
    }

    private function readPlan(WorkoutId $id, bool $lock): WorkoutPlan
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workout_plans WHERE id=?'.($lock ? ' FOR UPDATE' : ''), [$id->toString()]);
        if (false === $row) {
            throw new WorkoutNotFound('Workout not found.');
        }

        return new WorkoutPlan($id, WorkoutRevisionId::fromString($row['current_revision_id']), new WorkoutVersion((int) $row['version']),
            $this->instant($row['created_at']), $this->instant($row['updated_at']));
    }

    /** Keep caller savepoints and preserve the primary exception, including an uncertain COMMIT response. */
    private function transaction(\Closure $operation): WorkoutPlan
    {
        $level = $this->connection->getTransactionNestingLevel();
        $committing = false;
        try {
            $this->connection->beginTransaction();
            $plan = $operation();
            $committing = true;
            $this->connection->commit();

            return $plan;
        } catch (\Throwable $failure) {
            if ($this->connection->getTransactionNestingLevel() > $level) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable) {
                    $this->connection->close();
                }
            } elseif ($committing) {
                $this->connection->close();
            }
            throw $failure;
        }
    }

    private function time(Instant $value): string
    {
        return $value->toDateTimeImmutable()->format('Y-m-d H:i:s.uP');
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(new \DateTimeImmutable($value));
    }
}
