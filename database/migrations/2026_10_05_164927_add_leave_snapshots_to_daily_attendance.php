<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->unique(['company_id', 'id', 'employee_id', 'leave_type_id'], 'lr_company_id_employee_type_unique');
        });

        Schema::table('leave_request_days', function (Blueprint $table): void {
            $table->unique(['company_id', 'employee_id', 'leave_request_id', 'id'], 'lrd_company_employee_request_id_unique');
        });

        Schema::table('daily_attendance_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->unsignedBigInteger('leave_request_day_id')->nullable();
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->decimal('leave_amount', 8, 2)->nullable();
            $table->boolean('leave_is_paid')->nullable();

            $table->foreign(['company_id', 'employee_id', 'leave_request_id', 'leave_type_id'], 'dar_leave_request_ownership_foreign')
                ->references(['company_id', 'id', 'employee_id', 'leave_type_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'leave_request_id', 'leave_request_day_id'], 'dar_leave_day_ownership_foreign')
                ->references(['company_id', 'employee_id', 'leave_request_id', 'id'])->on('leave_request_days')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'dar_leave_type_ownership_foreign')
                ->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
        });

        Schema::table('daily_attendance_record_histories', function (Blueprint $table): void {
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->unsignedBigInteger('leave_request_day_id')->nullable();
            $table->unsignedBigInteger('leave_type_id')->nullable();
            $table->decimal('leave_amount', 8, 2)->nullable();
            $table->boolean('leave_is_paid')->nullable();

            $table->foreign(['company_id', 'employee_id', 'leave_request_id', 'leave_type_id'], 'darh_leave_request_ownership_foreign')
                ->references(['company_id', 'id', 'employee_id', 'leave_type_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'leave_request_id', 'leave_request_day_id'], 'darh_leave_day_ownership_foreign')
                ->references(['company_id', 'employee_id', 'leave_request_id', 'id'])->on('leave_request_days')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'darh_leave_type_ownership_foreign')
                ->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE daily_attendance_records DROP CONSTRAINT dar_status_check');
            DB::statement("ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_status_check CHECK (status IN ('present', 'partial_leave', 'on_leave', 'half_day', 'absent', 'holiday', 'off_day', 'incomplete', 'no_schedule', 'in_progress', 'not_yet_due'))");
            DB::statement('ALTER TABLE daily_attendance_record_histories DROP CONSTRAINT darh_status_check');
            DB::statement("ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_status_check CHECK (status IN ('present', 'partial_leave', 'on_leave', 'half_day', 'absent', 'holiday', 'off_day', 'incomplete', 'no_schedule', 'in_progress', 'not_yet_due'))");
            DB::statement('ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_leave_amount_nonnegative_check CHECK (leave_amount IS NULL OR leave_amount >= 0)');
            DB::statement('ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_leave_amount_nonnegative_check CHECK (leave_amount IS NULL OR leave_amount >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE daily_attendance_records DROP CONSTRAINT IF EXISTS dar_status_check');
            DB::statement("ALTER TABLE daily_attendance_records ADD CONSTRAINT dar_status_check CHECK (status IN ('present', 'half_day', 'absent', 'holiday', 'off_day', 'incomplete', 'no_schedule', 'in_progress', 'not_yet_due'))");
            DB::statement('ALTER TABLE daily_attendance_record_histories DROP CONSTRAINT IF EXISTS darh_status_check');
            DB::statement("ALTER TABLE daily_attendance_record_histories ADD CONSTRAINT darh_status_check CHECK (status IN ('present', 'half_day', 'absent', 'holiday', 'off_day', 'incomplete', 'no_schedule', 'in_progress', 'not_yet_due'))");
        }

        Schema::table('daily_attendance_record_histories', function (Blueprint $table): void {
            $table->dropForeign('darh_leave_request_ownership_foreign');
            $table->dropForeign('darh_leave_day_ownership_foreign');
            $table->dropForeign('darh_leave_type_ownership_foreign');
            $table->dropColumn(['leave_request_id', 'leave_request_day_id', 'leave_type_id', 'leave_amount', 'leave_is_paid']);
        });

        Schema::table('daily_attendance_records', function (Blueprint $table): void {
            $table->dropForeign('dar_leave_request_ownership_foreign');
            $table->dropForeign('dar_leave_day_ownership_foreign');
            $table->dropForeign('dar_leave_type_ownership_foreign');
            $table->dropColumn(['leave_request_id', 'leave_request_day_id', 'leave_type_id', 'leave_amount', 'leave_is_paid']);
        });

        Schema::table('leave_request_days', function (Blueprint $table): void {
            $table->dropUnique('lrd_company_employee_request_id_unique');
        });
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropUnique('lr_company_id_employee_type_unique');
        });
    }
};
