<?php

declare(strict_types=1);

namespace Youmad\Endurance\WorkoutPostgresql;

final class WorkoutReadProjection
{
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
}
