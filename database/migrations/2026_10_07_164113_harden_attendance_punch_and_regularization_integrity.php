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
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $invalidLocationValues = DB::table('attendance_punches')
                ->whereRaw("latitude::text IN ('NaN', 'Infinity', '-Infinity')")
                ->orWhereRaw("longitude::text IN ('NaN', 'Infinity', '-Infinity')")
                ->orWhereRaw("location_accuracy_meters::text IN ('NaN', 'Infinity', '-Infinity')")
                ->orWhereRaw("location_distance_meters::text IN ('NaN', 'Infinity', '-Infinity')")
                ->exists();

            if ($invalidLocationValues) {
                throw new RuntimeException('Attendance punch location data contains non-finite numeric values. Investigate the affected records before installing attendance integrity constraints.');
            }

            $hasMismatchedDailyRecord = DB::table('attendance_regularization_requests as requests')
                ->leftJoin('daily_attendance_records as records', function ($join): void {
                    $join->on('records.company_id', '=', 'requests.company_id')
                        ->on('records.employee_id', '=', 'requests.employee_id')
                        ->on('records.attendance_date', '=', 'requests.attendance_date')
                        ->on('records.id', '=', 'requests.daily_attendance_record_id');
                })
                ->whereNotNull('requests.daily_attendance_record_id')
                ->whereNull('records.id')
                ->exists();

            if ($hasMismatchedDailyRecord) {
                throw new RuntimeException('Attendance regularization requests contain a daily record owned by another company or employee. Investigate the affected records before installing the ownership constraint.');
            }

            DB::statement('ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_company_employee_date_id_unique UNIQUE (company_id, employee_id, attendance_date, id)');
            DB::statement('ALTER TABLE attendance_regularization_requests ADD CONSTRAINT arr_company_employee_date_daily_record_foreign FOREIGN KEY (company_id, employee_id, attendance_date, daily_attendance_record_id) REFERENCES daily_attendance_records (company_id, employee_id, attendance_date, id) ON DELETE RESTRICT');
            DB::statement("ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_accuracy_finite_check CHECK (location_accuracy_meters IS NULL OR (location_accuracy_meters >= 0 AND location_accuracy_meters < 'Infinity'::numeric))");
            DB::statement("ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_distance_finite_check CHECK (location_distance_meters IS NULL OR (location_distance_meters >= 0 AND location_distance_meters < 'Infinity'::numeric))");
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_attendance_punch_mutation() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Raw attendance punches are append-only.'
                        USING ERRCODE = '23514';
                END;
                $$;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER attendance_punches_prevent_mutation
                BEFORE UPDATE OR DELETE ON attendance_punches
                FOR EACH ROW EXECUTE FUNCTION prevent_attendance_punch_mutation();
                SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER attendance_punches_prevent_update BEFORE UPDATE ON attendance_punches BEGIN SELECT RAISE(ABORT, 'Raw attendance punches are append-only.'); END");
            DB::unprepared("CREATE TRIGGER attendance_punches_prevent_delete BEFORE DELETE ON attendance_punches BEGIN SELECT RAISE(ABORT, 'Raw attendance punches are append-only.'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS attendance_punches_prevent_mutation ON attendance_punches');
            DB::statement('DROP FUNCTION IF EXISTS prevent_attendance_punch_mutation()');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_accuracy_finite_check');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_distance_finite_check');
            DB::statement('ALTER TABLE attendance_regularization_requests DROP CONSTRAINT IF EXISTS arr_company_employee_date_daily_record_foreign');
            DB::statement('ALTER TABLE daily_attendance_records DROP CONSTRAINT IF EXISTS dar_company_employee_date_id_unique');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS attendance_punches_prevent_update');
            DB::statement('DROP TRIGGER IF EXISTS attendance_punches_prevent_delete');
        }
    }
};
