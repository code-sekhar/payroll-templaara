<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_leave_policy_assignments', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'id'],
                'elpa_account_ownership_unique'
            );
        });

        Schema::create('employee_leave_balance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_policy_id');
            $table->unsignedBigInteger('employee_leave_policy_assignment_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['company_id', 'employee_id'], 'elba_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'elba_company_type_foreign')
                ->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id', 'leave_policy_id'], 'elba_company_policy_foreign')
                ->references(['company_id', 'leave_type_id', 'id'])->on('leave_policies')->restrictOnDelete();
            $table->foreign(
                ['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'],
                'elba_assignment_ownership_foreign'
            )->references(['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'id'])
                ->on('employee_leave_policy_assignments')->restrictOnDelete();

            $table->unique(
                ['company_id', 'employee_id', 'leave_type_id', 'period_start', 'period_end'],
                'elba_logical_period_unique'
            );
            $table->unique(
                ['company_id', 'id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'],
                'elba_ledger_ownership_unique'
            );
            $table->index(['company_id', 'status', 'period_start', 'period_end'], 'elba_listing_period_index');
            $table->index(['company_id', 'employee_id', 'leave_type_id', 'period_start'], 'elba_employee_type_period_index');
            $table->index(['company_id', 'leave_policy_id', 'status'], 'elba_policy_status_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE employee_leave_balance_accounts ADD CONSTRAINT elba_valid_status CHECK (status IN ('open', 'closed'))");
            DB::statement('ALTER TABLE employee_leave_balance_accounts ADD CONSTRAINT elba_valid_period CHECK (period_end >= period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_balance_accounts');

        Schema::table('employee_leave_policy_assignments', function (Blueprint $table): void {
            $table->dropUnique('elpa_account_ownership_unique');
        });
    }
};
