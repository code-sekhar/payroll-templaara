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
        Schema::table('employee_work_schedule_assignments', function (Blueprint $table): void {
            $table->unique(['company_id', 'employee_id', 'id'], 'ewsa_company_employee_id_unique');
            $table->unique(['company_id', 'employee_id', 'work_schedule_id', 'id'], 'ewsa_company_employee_schedule_id_unique');
        });
        Schema::table('holidays', function (Blueprint $table): void {
            $table->unique(['company_id', 'holiday_calendar_id', 'id'], 'holidays_company_calendar_id_unique');
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_policy_id');
            $table->unsignedBigInteger('employee_leave_policy_assignment_id');
            $table->unsignedBigInteger('leave_balance_account_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('requested_days', 8, 2);
            $table->string('duration', 16)->default('full_day');
            $table->string('status', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->text('employee_note')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign(['company_id', 'employee_id'], 'lr_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'lr_company_type_foreign')
                ->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id', 'leave_policy_id'], 'lr_company_policy_foreign')
                ->references(['company_id', 'leave_type_id', 'id'])->on('leave_policies')->restrictOnDelete();
            $table->foreign(
                ['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'],
                'lr_assignment_ownership_foreign'
            )->references(['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'id'])
                ->on('employee_leave_policy_assignments')->restrictOnDelete();
            $table->foreign(
                ['company_id', 'leave_balance_account_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'],
                'lr_account_ownership_foreign'
            )->references(['company_id', 'id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'])
                ->on('employee_leave_balance_accounts')->restrictOnDelete();

            $table->unique(['company_id', 'id', 'employee_id'], 'lr_company_id_employee_unique');
            $table->index(['company_id', 'employee_id', 'status', 'start_date'], 'lr_employee_status_start_index');
            $table->index(['company_id', 'leave_type_id', 'status', 'start_date'], 'lr_type_status_start_index');
            $table->index(['company_id', 'status', 'requested_at'], 'lr_status_requested_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT lr_valid_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
            DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT lr_valid_duration CHECK (duration IN ('full_day', 'half_day'))");
            DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT lr_valid_period CHECK (end_date >= start_date)');
            DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT lr_positive_days CHECK (requested_days > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::table('employee_work_schedule_assignments', function (Blueprint $table): void {
            $table->dropUnique('ewsa_company_employee_schedule_id_unique');
            $table->dropUnique('ewsa_company_employee_id_unique');
        });
        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropUnique('holidays_company_calendar_id_unique');
        });
    }
};
