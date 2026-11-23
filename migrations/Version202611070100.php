<?php

declare(strict_types=1);

namespace Endurance\WorkoutPostgresql\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202611070100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immutable Workout snapshots and atomic versioned plan heads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE workout_plans (
    id UUID PRIMARY KEY,
    current_revision_id UUID NOT NULL,
    version BIGINT NOT NULL CHECK (version > 0),
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL CHECK (updated_at >= created_at)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE workout_revisions (
    id UUID PRIMARY KEY,
    workout_id UUID NOT NULL REFERENCES workout_plans(id) DEFERRABLE INITIALLY DEFERRED,
    plan_version BIGINT NOT NULL CHECK (plan_version > 0),
    created_at TIMESTAMPTZ NOT NULL,
    prescription TEXT NOT NULL CHECK (octet_length(prescription) <= 16777216),
    node_count INTEGER NOT NULL CHECK (node_count BETWEEN 1 AND 65534),
    occurrence_count BIGINT NOT NULL CHECK (occurrence_count > 0),
    CONSTRAINT workout_revision_version UNIQUE (workout_id, plan_version),
    CONSTRAINT workout_revision_snapshot UNIQUE (workout_id, id, plan_version, created_at),
    CONSTRAINT workout_prescription_shape CHECK (
        jsonb_typeof(prescription::jsonb) = 'object'
        AND COALESCE(prescription::jsonb ->> 'format' = 'workout-prescription.v1', FALSE)
        AND CASE WHEN jsonb_typeof(prescription::jsonb -> 'nodes') = 'array'
            THEN jsonb_array_length(prescription::jsonb -> 'nodes') = node_count ELSE FALSE END
    )
)
SQL);
        $this->addSql('ALTER TABLE workout_plans ADD CONSTRAINT workout_head_snapshot FOREIGN KEY (id,current_revision_id,version,updated_at) REFERENCES workout_revisions(workout_id,id,plan_version,created_at)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION endurance_workout_revision_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Workout revision history is immutable' USING ERRCODE='23514';
END
$$
SQL);
        $this->addSql('CREATE TRIGGER workout_revision_immutable BEFORE UPDATE OR DELETE ON workout_revisions FOR EACH ROW EXECUTE FUNCTION endurance_workout_revision_immutable()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION endurance_workout_head_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.version <> 1 OR NEW.created_at IS DISTINCT FROM NEW.updated_at THEN
            RAISE EXCEPTION 'Workout must start at version one' USING ERRCODE='23514';
        END IF;
    ELSE
        IF NEW.id IS DISTINCT FROM OLD.id OR NEW.created_at IS DISTINCT FROM OLD.created_at
            OR NEW.version <> OLD.version + 1 OR NEW.current_revision_id = OLD.current_revision_id
            OR NEW.updated_at < OLD.updated_at THEN
            RAISE EXCEPTION 'Invalid Workout head transition' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END
$$
SQL);
        $this->addSql('CREATE TRIGGER workout_head_transition BEFORE INSERT OR UPDATE ON workout_plans FOR EACH ROW EXECUTE FUNCTION endurance_workout_head_transition()');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT count(*) FROM workout_plans') > 0
            || (int) $this->connection->fetchOne('SELECT count(*) FROM workout_revisions') > 0, 'Cannot discard Workout history.');
        $this->addSql('ALTER TABLE workout_plans DROP CONSTRAINT workout_head_snapshot');
        $this->addSql('DROP TABLE workout_revisions');
        $this->addSql('DROP TABLE workout_plans');
        $this->addSql('DROP FUNCTION endurance_workout_revision_immutable()');
        $this->addSql('DROP FUNCTION endurance_workout_head_transition()');
    }
}
