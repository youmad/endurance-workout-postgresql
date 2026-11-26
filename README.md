# Endurance Workout PostgreSQL

Doctrine DBAL storage for immutable Workout plans/revisions. Requires PHP 8.5,
PostgreSQL 18, PDO pgsql and Doctrine DBAL ^4.4.

```bash
composer require endurance/workout-postgresql
```

Register `migrations/` under namespace
`Endurance\WorkoutPostgresql\Migrations` in your migration runner and apply
it before use. No automatic schema creation; retained publication history prevents
destructive rollback.

```php
use Endurance\Foundation\Clock\SystemClock;
use Endurance\Workout\Application\UseCase\PublishWorkout;
use Endurance\WorkoutPostgresql\DoctrineDbalWorkoutRepository;

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

For bounded structural reads, select
`WorkoutReadProjection::contentColumns('r', $limit, $after)` from
`workout_revisions r`, adding your authorization and exact workout/revision filters
to the same query. Map the row with `WorkoutReadProjection::contentPage($row,
$limit, $after)`. Limits are 1–100; `after` is an exclusive, one-based structural
ordinal (0 initially, at most 65534). The page retains its revision, publication
time and unexpanded repeat counts; parents can precede the page. A row after the
last node yields an empty page with its header. A missing row remains missing.
The projection validates the header and selected nodes without transferring or
hydrating the complete recipe. `getRevision()` validates and loads the full tree.

Types in `Internal` and elements marked `@internal` are not public API.

License: [MPL-2.0](LICENSE).
