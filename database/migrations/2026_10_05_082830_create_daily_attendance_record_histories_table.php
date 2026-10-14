<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_attendance_record_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('daily_attendance_record_id');
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
            $table->unsignedSmallInteger('calculation_version')->default(1);
            $table->timestampTz('calculated_at', precision: 6);
            $table->string('change_reason', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at', precision: 6)->useCurrent();

            $table->foreign(['company_id', 'daily_attendance_record_id'], 'darh_company_record_foreign')
                ->references(['company_id', 'id'])->on('daily_attendance_records')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'darh_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'attendance_date'], 'darh_company_employee_date_foreign')
                ->references(['company_id', 'employee_id', 'attendance_date'])->on('daily_attendance_records')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'attendance_date', 'created_at'], 'darh_company_employee_date_created_index');
            $table->index(['daily_attendance_record_id', 'created_at'], 'darh_record_created_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_status_check CHECK (status IN ('present', 'half_day', 'absent', 'holiday', 'off_day', 'incomplete', 'no_schedule', 'in_progress', 'not_yet_due'))");
            DB::statement("ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_state_check CHECK (record_state IN ('provisional', 'final', 'needs_review'))");
            DB::statement("ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_reason_check CHECK (change_reason IN ('initial_materialization', 'recalculation'))");
            DB::statement('ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_minutes_nonnegative_check CHECK (expected_minutes >= 0 AND worked_minutes >= 0 AND late_minutes >= 0 AND early_leave_minutes >= 0 AND overtime_minutes >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendance_record_histories');
    }
};
