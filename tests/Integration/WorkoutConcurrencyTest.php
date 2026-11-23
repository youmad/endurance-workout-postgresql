<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Tests\Integration;

use Endurance\Workout\Exception\WorkoutAlreadyExists;
use Endurance\Workout\Exception\WorkoutConflict;
use Endurance\Workout\Exception\WorkoutRevisionAlreadyExists;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\WorkoutPostgresql\Tests\Support\StorageTestCase;

final class WorkoutConcurrencyTest extends StorageTestCase
{
    public function testTwoReplacementsHaveOneWinnerAndNoUnusedSnapshot(): void
    {
        $old = $this->create();
        $this->db->beginTransaction();
        $winner = $this->publisher()->revise($old->id, $old->expectation(), WorkoutRevisionId::generate(), $this->prescription('winner'));
        $status = $this->race(['operation' => 'revise', 'id' => $old->id->toString(), 'revision' => WorkoutRevisionId::generate()->toString(),
            'version' => 1, 'expected' => $old->currentRevisionId->toString()], fn () => $this->db->commit());
        self::assertSame(WorkoutConflict::class, $status);
        self::assertEquals($winner, $this->storage->get($old->id));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
    }

    public function testConcurrentFirstPublicationKeepsOnlyOneIdentityAndRevision(): void
    {
        $id = WorkoutId::generate();
        $this->db->beginTransaction();
        $this->publisher()->create($id, WorkoutRevisionId::generate(), $this->prescription());
        $status = $this->race(['operation' => 'create', 'id' => $id->toString(), 'revision' => WorkoutRevisionId::generate()->toString()], fn () => $this->db->commit());
        self::assertSame(WorkoutAlreadyExists::class, $status);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_plans'));
    }

    public function testGlobalRevisionReservationSerializesAcrossDifferentPlans(): void
    {
        $revision = WorkoutRevisionId::generate();
        $this->db->beginTransaction();
        $this->publisher()->create(WorkoutId::generate(), $revision, $this->prescription());
        $status = $this->race(['operation' => 'create', 'id' => WorkoutId::generate()->toString(), 'revision' => $revision->toString()], fn () => $this->db->commit());
        self::assertSame(WorkoutRevisionAlreadyExists::class, $status);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM workout_plans'));
    }

    public function testWaitingWriterCanPublishWhenTheOtherTransactionRollsBack(): void
    {
        $old = $this->create();
        $this->db->beginTransaction();
        $this->publisher()->revise($old->id, $old->expectation(), WorkoutRevisionId::generate(), $this->prescription('rolled back'));
        $revision = WorkoutRevisionId::generate();
        $status = $this->race(['operation' => 'revise', 'id' => $old->id->toString(), 'revision' => $revision->toString(),
            'version' => 1, 'expected' => $old->currentRevisionId->toString()], fn () => $this->db->rollBack());
        self::assertSame('ok', $status);
        self::assertTrue($this->storage->get($old->id)->currentRevisionId->equals($revision));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM workout_revisions'));
    }

    /** @param array<string,int|string> $payload */
    private function race(array $payload, \Closure $release): string
    {
        $autoload = getenv('ENDURANCE_WORKOUT_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';
        $tag = 'workout-race-'.bin2hex(random_bytes(6));
        $path = tempnam(sys_get_temp_dir(), 'workout-race-');
        file_put_contents($path, json_encode($payload + ['tag' => $tag], JSON_THROW_ON_ERROR));
        $process = null;
        $pipes = [];
        try {
            $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY, dirname(__DIR__).'/Support/concurrent-worker.php', $autoload, $path],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 12);
            self::assertSame("READY\n", fgets($pipes[1]));
            $deadline = microtime(true) + 8;
            $blocked = false;
            do {
                $blocked = false !== $this->db->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$tag]);
                if ($blocked) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($blocked, 'The independent writer must really wait on PostgreSQL.');
            $release();
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            self::assertSame(0, proc_close($process), $errors);
            $process = null;

            return json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'];
        } finally {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            unlink($path);
        }
    }
}
