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
        Schema::table('leave_policies', function (Blueprint $table): void {
            $table->unique(['company_id', 'leave_type_id', 'id'], 'leave_policies_company_type_id_unique');
        });

        Schema::create('employee_leave_policy_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_policy_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['company_id', 'employee_id'], 'elpa_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'elpa_company_type_foreign')
                ->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id', 'leave_policy_id'], 'elpa_company_type_policy_foreign')
                ->references(['company_id', 'leave_type_id', 'id'])->on('leave_policies')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'leave_type_id', 'status', 'effective_from'], 'elpa_employee_type_status_from_index');
            $table->index(['company_id', 'leave_policy_id', 'effective_from'], 'elpa_policy_from_index');
            $table->index(['company_id', 'status', 'effective_from'], 'elpa_status_from_index');
            $table->index(['company_id', 'effective_to'], 'elpa_to_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_leave_policy_assignments ADD CONSTRAINT elpa_valid_period CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE employee_leave_policy_assignments ADD CONSTRAINT elpa_valid_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_leave_policy_assignments');

        Schema::table('leave_policies', function (Blueprint $table): void {
            $table->dropUnique('leave_policies_company_type_id_unique');
        });
    }
};
