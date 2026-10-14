<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $invalidScheduleCount = (int) DB::table('work_schedules as schedule')
            ->whereRaw('(SELECT COUNT(*) FROM work_schedule_days AS schedule_day WHERE schedule_day.company_id = schedule.company_id AND schedule_day.work_schedule_id = schedule.id) <> 7')
            ->count();

        if ($invalidScheduleCount > 0) {
            throw new RuntimeException("Cannot repair the Work Schedule integrity trigger: {$invalidScheduleCount} existing schedules do not have exactly seven day records. Investigate these schedules before retrying the migration.");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_complete_work_schedule_days() RETURNS trigger AS $$
            DECLARE
                target_schedule_id BIGINT;
                target_company_id BIGINT;
                day_count INTEGER;
            BEGIN
                IF TG_TABLE_NAME = 'work_schedules' THEN
                    target_schedule_id := NEW.id;
                    target_company_id := NEW.company_id;
                ELSIF TG_TABLE_NAME = 'work_schedule_days' THEN
                    IF TG_OP = 'DELETE' THEN
                        target_schedule_id := OLD.work_schedule_id;
                        target_company_id := OLD.company_id;
                    ELSE
                        target_schedule_id := NEW.work_schedule_id;
                        target_company_id := NEW.company_id;
                    END IF;
                ELSE
                    RAISE EXCEPTION 'Unsupported table for Work Schedule completeness validation.'
                        USING ERRCODE = '0A000';
                END IF;

                SELECT COUNT(*) INTO day_count
                FROM work_schedule_days
                WHERE company_id = target_company_id
                    AND work_schedule_id = target_schedule_id;

                IF day_count <> 7 THEN
                    RAISE EXCEPTION 'A work schedule must have exactly seven day records.'
                        USING ERRCODE = '23514';
                END IF;

                IF TG_TABLE_NAME = 'work_schedule_days' THEN
                    IF TG_OP = 'UPDATE' THEN
                        IF OLD.company_id IS DISTINCT FROM NEW.company_id
                            OR OLD.work_schedule_id IS DISTINCT FROM NEW.work_schedule_id THEN
                            SELECT COUNT(*) INTO day_count
                            FROM work_schedule_days
                            WHERE company_id = OLD.company_id
                                AND work_schedule_id = OLD.work_schedule_id;

                            IF day_count <> 7 THEN
                                RAISE EXCEPTION 'A work schedule must have exactly seven day records.'
                                    USING ERRCODE = '23514';
                            END IF;
                        END IF;
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_complete_work_schedule_days() RETURNS trigger AS $$
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
    }
};
