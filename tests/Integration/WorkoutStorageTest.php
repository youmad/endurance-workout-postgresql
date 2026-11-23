<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Endurance\Foundation\Clock\FrozenClock;
use Endurance\Workout\Application\UseCase\PublishWorkout;
use Endurance\Workout\Exception\WorkoutAlreadyExists;
use Endurance\Workout\Exception\WorkoutConflict;
use Endurance\Workout\Exception\WorkoutNotFound;
use Endurance\Workout\Exception\WorkoutRevisionAlreadyExists;
use Endurance\Workout\Exception\WorkoutRevisionNotFound;
use Endurance\Workout\ValueObject\ExpectedWorkoutState;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\Workout\ValueObject\WorkoutRevisionReference;
use Endurance\Workout\ValueObject\WorkoutVersion;
use Endurance\WorkoutPostgresql\DoctrineDbalWorkoutRepository;
use Endurance\WorkoutPostgresql\Tests\Support\StorageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkoutStorageTest extends StorageTestCase
{
    public function testPublishedSnapshotsAndApplicationTimeSurviveReconnection(): void
    {
        $first = $this->create();
        $new = $this->publisher()->revise($first->id, $first->expectation(), WorkoutRevisionId::generate(), $this->prescription('Changed'));
        $other = self::connect();
        try {
            $reader = new DoctrineDbalWorkoutRepository($other);
            self::assertEquals($new, $reader->get($first->id));
            self::assertSame('План', $reader->getRevision($first->reference())->prescription->name);
            self::assertSame('Changed', $reader->getRevision($new->reference())->prescription->name);
            self::assertSame(4, $reader->getRevision($first->reference())->prescription->steps->occurrenceCount);
            self::assertTrue($reader->getRevision($first->reference())->createdAt->equals($this->instant('2026-11-07T07:00:00.123456Z')));
            self::assertSame(2, (int) $other->fetchOne('SELECT count(*) FROM workout_revisions'));
        } finally {
            $other->close();
        }
    }

    #[DataProvider('staleFields')]
    public function testBothExpectedFieldsAreCheckedUnderTheHeadLock(bool $wrongVersion, bool $wrongRevision): void
    {
        $old = $this->create();
        $current = $this->publisher()->revise($old->id, $old->expectation(), WorkoutRevisionId::generate(), $this->prescription());
        $expected = new ExpectedWorkoutState($wrongVersion ? $old->version : $current->version, $wrongRevision ? $old->currentRevisionId : $current->currentRevisionId);
        try {
            $this->publisher()->revise($old->id, $expected, WorkoutRevisionId::generate(), $this->prescription());
            self::fail('Stale publication succeeded.');
        } catch (WorkoutConflict) {
            self::assertEquals($current, $this->storage->get($old->id));
            self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
        }
    }

    /** @return iterable<string,array{bool,bool}> */
    public static function staleFields(): iterable
    {
        yield 'version only' => [true, false];
        yield 'revision only' => [false, true];
        yield 'both' => [true, true];
    }

    public function testOldRevisionIdentityCannotBeReusedWithinOrAcrossPlans(): void
    {
        $first = $this->create();
        $current = $this->publisher()->revise($first->id, $first->expectation(), WorkoutRevisionId::generate(), $this->prescription());
        foreach ([false, true] as $anotherPlan) {
            try {
                if ($anotherPlan) {
                    $this->publisher()->create(WorkoutId::generate(), $first->currentRevisionId, $this->prescription('Forged'));
                } else {
                    $this->publisher()->revise($first->id, $current->expectation(), $first->currentRevisionId, $this->prescription('Forged'));
                }
                self::fail('Historical identity was reused.');
            } catch (WorkoutRevisionAlreadyExists) {
                self::assertEquals($current, $this->storage->get($first->id));
                self::assertSame('План', $this->storage->getRevision($first->reference())->prescription->name);
                self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_plans'));
                self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
            }
        }
    }

    public function testDuplicatePlanCreationDoesNotLeakAnotherRevision(): void
    {
        $plan = $this->create();
        try {
            $this->publisher()->create($plan->id, WorkoutRevisionId::generate(), $this->prescription());
            self::fail('Plan was recreated.');
        } catch (WorkoutAlreadyExists) {
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
        }
    }

    public function testFailedHeadWriteRollsBackThePreparedRevision(): void
    {
        $old = $this->create();
        $this->db->executeStatement("CREATE FUNCTION workout_fail_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected head failure'; END $$");
        $this->db->executeStatement('CREATE TRIGGER workout_fail_test BEFORE UPDATE ON workout_plans FOR EACH ROW EXECUTE FUNCTION workout_fail_test()');
        try {
            try {
                $this->publisher()->revise($old->id, $old->expectation(), WorkoutRevisionId::generate(), $this->prescription());
                self::fail('Head failure was swallowed.');
            } catch (DriverException $failure) {
                self::assertStringContainsString('injected head failure', $failure->getMessage());
            }
            self::assertEquals($old, $this->storage->get($old->id));
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
        } finally {
            $this->db->executeStatement('DROP TRIGGER workout_fail_test ON workout_plans');
            $this->db->executeStatement('DROP FUNCTION workout_fail_test()');
        }
    }

    public function testCallerTransactionControlsVisibilityAndRollback(): void
    {
        $other = self::connect();
        try {
            $this->db->beginTransaction();
            $created = $this->create();
            self::assertSame(1, $this->db->getTransactionNestingLevel());
            self::assertSame(0, (int) $other->fetchOne('SELECT count(*) FROM workout_plans'));
            $this->db->rollBack();
            self::assertSame(0, (int) $other->fetchOne('SELECT count(*) FROM workout_revisions'));
            $this->db->beginTransaction();
            $created = $this->create();
            $this->db->commit();
            self::assertEquals($created, new DoctrineDbalWorkoutRepository($other)->get($created->id));
        } finally {
            $other->close();
        }
    }

    public function testAConflictRollsBackOnlyItsOwnSavepoint(): void
    {
        $old = $this->create();
        $this->db->beginTransaction();
        $otherPlan = $this->create();
        try {
            $this->publisher()->revise($old->id, new ExpectedWorkoutState(new WorkoutVersion(2), $old->currentRevisionId), WorkoutRevisionId::generate(), $this->prescription());
            self::fail('Conflict was hidden.');
        } catch (WorkoutConflict) {
            self::assertSame(1, $this->db->getTransactionNestingLevel());
            self::assertEquals($otherPlan, $this->storage->get($otherPlan->id));
        }
        $this->db->commit();
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM workout_plans'));
    }

    public function testMissingPlanAndWrongParentReferenceAreNotResolvedToAnotherSnapshot(): void
    {
        $old = $this->create();
        try {
            $this->storage->get(WorkoutId::generate());
            self::fail('Missing plan found.');
        } catch (WorkoutNotFound) {
        }
        $this->expectException(WorkoutRevisionNotFound::class);
        $this->storage->getRevision(new WorkoutRevisionReference(WorkoutId::generate(), $old->currentRevisionId));
    }

    public function testBackdatedPublicationsLeaveNoHistory(): void
    {
        $old = $this->create();
        $publisher = new PublishWorkout($this->storage, new FrozenClock($this->instant('2026-11-07T06:00:00Z')));
        try {
            $publisher->revise($old->id, $old->expectation(), WorkoutRevisionId::generate(), $this->prescription());
            self::fail('Backdated publication succeeded.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
        }
    }

    public function testSqlCannotMutateSnapshotsOrRetargetTheHeadToHistoryOrAnotherParent(): void
    {
        $old = $this->create();
        $current = $this->publisher()->revise($old->id, $old->expectation(), WorkoutRevisionId::generate(), $this->prescription());
        $other = $this->create();
        foreach (['UPDATE workout_revisions SET prescription=prescription', 'DELETE FROM workout_revisions',
            'UPDATE workout_plans SET version=version+1', 'UPDATE workout_plans SET created_at=created_at - INTERVAL \'1 second\''] as $sql) {
            try {
                $this->db->executeStatement($sql);
                self::fail('Immutable content or coupled head was mutated.');
            } catch (DriverException $failure) {
                self::assertSame('23514', $failure->getSQLState());
            }
        }
        foreach ([$old->currentRevisionId, $other->currentRevisionId] as $invalid) {
            try {
                $this->db->executeStatement('UPDATE workout_plans SET current_revision_id=?,version=version+1 WHERE id=?', [$invalid->toString(), $current->id->toString()]);
                self::fail('Head was retargeted to an incompatible snapshot.');
            } catch (DriverException $failure) {
                self::assertSame('23503', $failure->getSQLState());
            }
        }
        self::assertEquals($current, $this->storage->get($current->id));
    }

    public function testLostCommitAcknowledgementPreservesTheErrorAndTheSingleCommittedRevision(): void
    {
        $old = $this->create();
        $id = WorkoutRevisionId::generate();
        $probe = new class($this->db->getParams(), $this->db->getDriver(), $this->db->getConfiguration()) extends Connection {
            public bool $lose = true;

            public function commit(): void
            {
                parent::commit();
                if ($this->lose) {
                    $this->lose = false;
                    throw new \RuntimeException('lost Workout COMMIT acknowledgement');
                }
            }
        };
        try {
            try {
                $this->publisher($probe)->revise($old->id, $old->expectation(), $id, $this->prescription('Committed'));
                self::fail('Lost acknowledgement was hidden.');
            } catch (\RuntimeException $failure) {
                self::assertSame('lost Workout COMMIT acknowledgement', $failure->getMessage());
            }
            self::assertFalse($probe->isTransactionActive());
            $stored = $this->storage->get($old->id);
            self::assertTrue($stored->currentRevisionId->equals($id));
            self::assertSame(2, $stored->version->value);
            self::assertSame('Committed', $this->storage->getRevision($stored->reference())->prescription->name);
            try {
                $this->publisher()->revise($old->id, $old->expectation(), $id, $this->prescription());
                self::fail('Uncertain write was duplicated.');
            } catch (WorkoutConflict) {
                self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
            }
        } finally {
            $probe->close();
        }
    }

    public function testDeferredCommitFailureIsNotMaskedByANoActiveTransactionError(): void
    {
        $this->db->executeStatement("CREATE FUNCTION workout_fail_commit_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'deferred Workout failure' USING ERRCODE='23514'; END $$");
        $this->db->executeStatement('CREATE CONSTRAINT TRIGGER workout_fail_commit_test AFTER INSERT ON workout_plans DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workout_fail_commit_test()');
        $probe = self::connect();
        try {
            try {
                $this->publisher($probe)->create(WorkoutId::generate(), WorkoutRevisionId::generate(), $this->prescription());
                self::fail('Deferred failure was hidden.');
            } catch (DriverException $failure) {
                self::assertSame('23514', $failure->getSQLState());
                self::assertStringContainsString('deferred Workout failure', $failure->getMessage());
            }
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM workout_plans'));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
        } finally {
            $probe->close();
            $this->db->executeStatement('DROP TRIGGER workout_fail_commit_test ON workout_plans');
            $this->db->executeStatement('DROP FUNCTION workout_fail_commit_test()');
        }
    }
}
