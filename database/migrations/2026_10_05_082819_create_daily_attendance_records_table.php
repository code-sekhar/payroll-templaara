<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_schedule_assignments', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'ewsa_company_id_id_unique');
        });

        Schema::table('employee_holiday_calendar_assignments', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'ehca_company_id_id_unique');
        });

        Schema::table('employee_attendance_policy_assignments', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'eapa_company_id_id_unique');
        });

        Schema::create('daily_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            $table->string('status', 24);
            $table->string('record_state', 20);
            $table->timestampTz('expected_start_at', precision: 6)->nullable();
            $table->timestampTz('expected_end_at', precision: 6)->nullable();
            $table->unsignedInteger('expected_minutes')->default(0);
            $table->timestampTz('first_check_in_at', precision: 6)->nullable();
            $table->timestampTz('last_check_out_at', precision: 6)->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->boolean('is_late')->default(false);
            $table->boolean('is_early_leave')->default(false);
            $table->unsignedBigInteger('work_schedule_id')->nullable();
            $table->unsignedBigInteger('work_schedule_assignment_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('holiday_calendar_id')->nullable();
            $table->unsignedBigInteger('holiday_id')->nullable();
            $table->unsignedBigInteger('attendance_policy_id')->nullable();
            $table->unsignedBigInteger('attendance_policy_assignment_id')->nullable();
            $table->timestampTz('calculated_at', precision: 6);
            $table->timestampTz('finalized_at', precision: 6)->nullable();
            $table->unsignedSmallInteger('calculation_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'attendance_date'], 'dar_company_employee_date_unique');
            $table->unique(['company_id', 'id'], 'dar_company_id_id_unique');
            $table->foreign(['company_id', 'employee_id'], 'dar_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'work_schedule_id'], 'dar_company_schedule_foreign')
                ->references(['company_id', 'id'])->on('work_schedules')->restrictOnDelete();
            $table->foreign(['company_id', 'work_schedule_assignment_id'], 'dar_company_schedule_assignment_foreign')
                ->references(['company_id', 'id'])->on('employee_work_schedule_assignments')->restrictOnDelete();
            $table->foreign(['company_id', 'shift_id'], 'dar_company_shift_foreign')
                ->references(['company_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->foreign(['company_id', 'holiday_calendar_id'], 'dar_company_holiday_calendar_foreign')
                ->references(['company_id', 'id'])->on('holiday_calendars')->restrictOnDelete();
            $table->foreign(['company_id', 'holiday_id'], 'dar_company_holiday_foreign')
                ->references(['company_id', 'id'])->on('holidays')->restrictOnDelete();
            $table->foreign(['company_id', 'attendance_policy_id'], 'dar_company_policy_foreign')
                ->references(['company_id', 'id'])->on('attendance_policies')->restrictOnDelete();
            $table->foreign(['company_id', 'attendance_policy_assignment_id'], 'dar_company_policy_assignment_foreign')
                ->references(['company_id', 'id'])->on('employee_attendance_policy_assignments')->restrictOnDelete();
            $table->index(['company_id', 'attendance_date', 'status'], 'dar_company_date_status_index');
            $table->index(['company_id', 'record_state', 'attendance_date'], 'dar_company_state_date_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_status_check CHECK (status IN ('present', 'half_day', 'absent', 'holiday', 'off_day', 'incomplete', 'no_schedule', 'in_progress', 'not_yet_due'))");
            DB::statement("ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_state_check CHECK (record_state IN ('provisional', 'final', 'needs_review'))");
            DB::statement('ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_minutes_nonnegative_check CHECK (expected_minutes >= 0 AND worked_minutes >= 0 AND late_minutes >= 0 AND early_leave_minutes >= 0 AND overtime_minutes >= 0)');
            DB::statement('ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_calculation_version_check CHECK (calculation_version >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendance_records');

        Schema::table('employee_attendance_policy_assignments', function (Blueprint $table): void {
            $table->dropUnique('eapa_company_id_id_unique');
        });

        Schema::table('employee_holiday_calendar_assignments', function (Blueprint $table): void {
            $table->dropUnique('ehca_company_id_id_unique');
        });

        Schema::table('employee_work_schedule_assignments', function (Blueprint $table): void {
            $table->dropUnique('ewsa_company_id_id_unique');
        });
    }
};
