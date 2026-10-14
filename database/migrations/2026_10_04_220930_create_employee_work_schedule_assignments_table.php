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
        Schema::create('employee_work_schedule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('work_schedule_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['company_id', 'employee_id'], 'ewsa_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'work_schedule_id'], 'ewsa_company_schedule_foreign')
                ->references(['company_id', 'id'])->on('work_schedules')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'status', 'effective_from'], 'ewsa_company_employee_status_from_index');
            $table->index(['company_id', 'work_schedule_id', 'effective_from'], 'ewsa_company_schedule_from_index');
            $table->index(['company_id', 'status', 'effective_from'], 'ewsa_company_status_from_index');
            $table->index(['company_id', 'effective_to'], 'ewsa_company_to_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_work_schedule_assignments ADD CONSTRAINT ewsa_valid_period CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE employee_work_schedule_assignments ADD CONSTRAINT ewsa_valid_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_work_schedule_assignments');
    }
};
