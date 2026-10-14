<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE work_schedule_days ADD CONSTRAINT work_schedule_days_valid_configuration CHECK (day_of_week BETWEEN 1 AND 7 AND ((day_type = 'off' AND shift_id IS NULL) OR (day_type IN ('working', 'half_day') AND shift_id IS NOT NULL)))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION enforce_complete_work_schedule_days() RETURNS trigger AS $$
            DECLARE
                target_schedule_id BIGINT;
                day_count INTEGER;
            BEGIN
                IF TG_TABLE_NAME = 'work_schedules' THEN
                    target_schedule_id := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    target_schedule_id := OLD.work_schedule_id;
                ELSE
                    target_schedule_id := NEW.work_schedule_id;
                END IF;

                SELECT COUNT(*) INTO day_count
                FROM work_schedule_days
                WHERE work_schedule_id = target_schedule_id;

                IF day_count <> 7 THEN
                    RAISE EXCEPTION 'A work schedule must have exactly seven day records.'
                        USING ERRCODE = '23514';
                END IF;

                IF TG_TABLE_NAME = 'work_schedule_days'
                    AND TG_OP = 'UPDATE'
                    AND OLD.work_schedule_id IS DISTINCT FROM NEW.work_schedule_id THEN
                    SELECT COUNT(*) INTO day_count
                    FROM work_schedule_days
                    WHERE work_schedule_id = OLD.work_schedule_id;

                    IF day_count <> 7 THEN
                        RAISE EXCEPTION 'A work schedule must have exactly seven day records.'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER work_schedules_require_seven_days
            AFTER INSERT ON work_schedules
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_complete_work_schedule_days();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER work_schedule_days_require_seven_days
            AFTER INSERT OR UPDATE OR DELETE ON work_schedule_days
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_complete_work_schedule_days();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS work_schedule_days_require_seven_days ON work_schedule_days');
        DB::unprepared('DROP TRIGGER IF EXISTS work_schedules_require_seven_days ON work_schedules');
        DB::unprepared('DROP FUNCTION IF EXISTS enforce_complete_work_schedule_days()');
        DB::statement('ALTER TABLE work_schedule_days DROP CONSTRAINT IF EXISTS work_schedule_days_valid_configuration');
    }
};
