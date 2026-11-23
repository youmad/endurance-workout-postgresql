# Endurance Workout PostgreSQL

Doctrine DBAL storage for immutable Workout plans/revisions. Requires PHP 8.5,
PostgreSQL 18, PDO pgsql and Doctrine DBAL ^4.4.

```bash
composer require youmad/endurance-workout-postgresql
```

Register `migrations/` under namespace
`Youmad\Endurance\WorkoutPostgresql\Migrations` in your migration runner and apply
it before use. No automatic schema creation; retained publication history prevents
destructive rollback.

```php
use Youmad\Endurance\Foundation\Clock\SystemClock;
use Youmad\Endurance\Workout\Application\UseCase\PublishWorkout;
use Youmad\Endurance\WorkoutPostgresql\DoctrineDbalWorkoutRepository;

$workouts = new DoctrineDbalWorkoutRepository($connection);
$publisher = new PublishWorkout($workouts, new SystemClock());
$plan = $publisher->create($workoutId, $revisionId, $prescription);
$revision = $workouts->getRevision($plan->reference());
```

- Storage is unscoped; the application authorizes access. Revision references
  identify exact immutable content, including retained history.
- Publication atomically checks both expected head fields and never reuses a
  stored revision ID. Use the same DBAL connection for shared transactions.
  A nested commit is not durable until the outer transaction commits; reconcile
  uncertain responses with the original IDs.
- Persistence retains exact float bits and repeat counts without expansion.
  The versioned storage encoding is private to this adapter.

Types in `Internal` and elements marked `@internal` are not public API.

License: [MPL-2.0](LICENSE).
