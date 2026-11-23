<?php

declare(strict_types=1);

$autoload = $argv[1] ?? throw new InvalidArgumentException('Expected consumer autoloader.');
$payload = $argv[2] ?? throw new InvalidArgumentException('Expected race payload.');
require $autoload;
require __DIR__.'/StorageTestCase.php';

use Endurance\Foundation\Clock\FrozenClock;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Workout\Application\UseCase\PublishWorkout;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\ValueObject\ExpectedWorkoutState;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\Workout\ValueObject\WorkoutVersion;
use Endurance\Workout\Workout;
use Endurance\WorkoutPostgresql\DoctrineDbalWorkoutRepository;
use Endurance\WorkoutPostgresql\Tests\Support\StorageTestCase;

$data = json_decode(file_get_contents($payload), true, flags: JSON_THROW_ON_ERROR);
$db = StorageTestCase::connect();
try {
    $db->executeStatement("SELECT set_config('application_name', ?, false)", [$data['tag']]);
    $db->executeStatement("SET lock_timeout='10s'");
    $publisher = new PublishWorkout(new DoctrineDbalWorkoutRepository($db), new FrozenClock(Instant::fromDateTimeImmutable(new DateTimeImmutable('2026-11-07T12:00:00.123456+05:00'))));
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    try {
        $id = WorkoutId::fromString($data['id']);
        $revision = WorkoutRevisionId::fromString($data['revision']);
        $value = new Workout(new Sequence(new Step(new OpenDuration())), 'worker');
        if ('create' === $data['operation']) {
            $publisher->create($id, $revision, $value);
        } else {
            $publisher->revise($id, new ExpectedWorkoutState(new WorkoutVersion($data['version']), WorkoutRevisionId::fromString($data['expected'])), $revision, $value);
        }
        $status = 'ok';
    } catch (Throwable $failure) {
        $status = $failure::class;
    }
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
} finally {
    $db->close();
}
