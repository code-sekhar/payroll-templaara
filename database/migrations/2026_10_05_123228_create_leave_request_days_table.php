<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_request_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('leave_request_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('leave_date');
            $table->string('day_type', 16);
            $table->decimal('requested_amount', 8, 2);
            $table->unsignedBigInteger('work_schedule_id')->nullable();
            $table->unsignedBigInteger('work_schedule_assignment_id')->nullable();
            $table->unsignedBigInteger('holiday_calendar_id')->nullable();
            $table->unsignedBigInteger('holiday_id')->nullable();
            $table->timestamps();

            $table->foreign(['company_id', 'leave_request_id', 'employee_id'], 'lrd_request_ownership_foreign')
                ->references(['company_id', 'id', 'employee_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'work_schedule_id'], 'lrd_company_schedule_foreign')
                ->references(['company_id', 'id'])->on('work_schedules')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'work_schedule_id', 'work_schedule_assignment_id'], 'lrd_schedule_assignment_ownership_foreign')
                ->references(['company_id', 'employee_id', 'work_schedule_id', 'id'])->on('employee_work_schedule_assignments')->restrictOnDelete();
            $table->foreign(['company_id', 'holiday_calendar_id'], 'lrd_company_calendar_foreign')
                ->references(['company_id', 'id'])->on('holiday_calendars')->restrictOnDelete();
            $table->foreign(['company_id', 'holiday_calendar_id', 'holiday_id'], 'lrd_company_calendar_holiday_foreign')
                ->references(['company_id', 'holiday_calendar_id', 'id'])->on('holidays')->restrictOnDelete();

            $table->unique(['company_id', 'leave_request_id', 'leave_date'], 'lrd_request_date_unique');
            $table->index(['company_id', 'employee_id', 'leave_date', 'leave_request_id'], 'lrd_employee_date_index');
            $table->index(['company_id', 'leave_date', 'day_type'], 'lrd_date_type_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_request_days ADD CONSTRAINT lrd_valid_type CHECK (day_type IN ('full_day', 'half_day', 'off_day', 'holiday'))");
            DB::statement('ALTER TABLE leave_request_days ADD CONSTRAINT lrd_nonnegative_amount CHECK (requested_amount >= 0)');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_leave_request_day_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Leave request day snapshots are immutable.' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER leave_request_days_no_update_delete
                BEFORE UPDATE OR DELETE ON leave_request_days
                FOR EACH ROW EXECUTE FUNCTION prevent_leave_request_day_mutation();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER leave_request_days_no_update BEFORE UPDATE ON leave_request_days BEGIN SELECT RAISE(ABORT, 'Leave request day snapshots are immutable.'); END");
            DB::statement("CREATE TRIGGER leave_request_days_no_delete BEFORE DELETE ON leave_request_days BEGIN SELECT RAISE(ABORT, 'Leave request day snapshots are immutable.'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS leave_request_days_no_update_delete ON leave_request_days');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_leave_request_day_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS leave_request_days_no_update');
            DB::statement('DROP TRIGGER IF EXISTS leave_request_days_no_delete');
        }

        Schema::dropIfExists('leave_request_days');
    }
};
