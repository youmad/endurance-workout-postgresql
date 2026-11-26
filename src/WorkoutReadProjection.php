<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql;

use Endurance\Foundation\ValueObject\Instant;
use Endurance\Workout\ValueObject\WorkoutId;
use Endurance\Workout\ValueObject\WorkoutRevisionId;
use Endurance\Workout\ValueObject\WorkoutRevisionReference;
use Endurance\WorkoutPostgresql\Internal\PrescriptionCodec;
use Endurance\WorkoutPostgresql\ReadModel\WorkoutContentPage;

final class WorkoutReadProjection
{
    public static function contentColumns(string $revisionAlias, int $limit = 50, int $after = 0): string
    {
        self::bounds($limit, $after);
        $revision = self::qualifier($revisionAlias);
        $last = $after + $limit - 1;

        return <<<SQL
            {$revision}workout_id, {$revision}id AS revision_id, {$revision}created_at AS published_at,
            {$revision}node_count, {$revision}occurrence_count,
            ({$revision}prescription::jsonb - 'nodes')::text AS content_header,
            jsonb_path_query_array({$revision}prescription::jsonb, '$.nodes[{$after} to {$last}]')::text AS content_nodes
            SQL;
    }

    /** @param array<string,mixed> $row */
    public static function contentPage(array $row, int $limit = 50, int $after = 0): WorkoutContentPage
    {
        self::bounds($limit, $after);
        try {
            $nodeCount = self::positiveInteger($row['node_count']);
            $occurrenceCount = self::positiveInteger($row['occurrence_count']);
            $content = new PrescriptionCodec()->decodePage(
                $row['content_header'], $row['content_nodes'], $nodeCount, $occurrenceCount, $limit, $after,
            );

            return new WorkoutContentPage(
                new WorkoutRevisionReference(WorkoutId::fromString($row['workout_id']), WorkoutRevisionId::fromString($row['revision_id'])),
                Instant::fromDateTimeImmutable(new \DateTimeImmutable($row['published_at'])),
                $content['name'], $content['description'], $occurrenceCount,
                $content['nodes'], $content['nextAfter'],
            );
        } catch (\InvalidArgumentException|\DateMalformedStringException|\TypeError $failure) {
            throw new \UnexpectedValueException('Invalid stored Workout content page.', previous: $failure);
        }
    }

    public static function summaryColumns(string $planAlias, string $revisionAlias): string
    {
        $plan = self::qualifier($planAlias);
        $revision = self::qualifier($revisionAlias);
        $name = self::revisionNameExpression($revisionAlias);

        return <<<SQL
            {$plan}id AS workout_id, {$plan}current_revision_id AS revision_id, {$plan}version,
            {$revision}created_at AS published_at, {$revision}occurrence_count,
            {$name} AS name,
            {$revision}prescription::jsonb->>'description' AS description
            SQL;
    }

    public static function revisionNameExpression(string $revisionAlias): string
    {
        return self::qualifier($revisionAlias)."prescription::jsonb->>'name'";
    }

    private static function qualifier(string $alias): string
    {
        if (1 !== preg_match('/\A[a-z_][a-z0-9_]*\z/', $alias)) {
            throw new \InvalidArgumentException('Expected a simple Workout projection table alias.');
        }

        return $alias.'.';
    }

    private static function bounds(int $limit, int $after): void
    {
        if ($limit < 1 || $limit > 100 || $after < 0 || $after > PrescriptionCodec::MAX_NODES) {
            throw new \InvalidArgumentException('Invalid Workout content page bounds.');
        }
    }

    private static function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('Invalid stored Workout count.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $integer) {
            throw new \UnexpectedValueException('Invalid stored Workout count.');
        }

        return $integer;
    }
}
