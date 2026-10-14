<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'attendance_policies_company_id_id_unique');
        });

        Schema::create('employee_attendance_policy_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('attendance_policy_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['company_id', 'employee_id'], 'eapa_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'attendance_policy_id'], 'eapa_company_policy_foreign')
                ->references(['company_id', 'id'])->on('attendance_policies')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'status', 'effective_from'], 'eapa_company_employee_status_from_index');
            $table->index(['company_id', 'attendance_policy_id', 'effective_from'], 'eapa_company_policy_from_index');
            $table->index(['company_id', 'status', 'effective_from'], 'eapa_company_status_from_index');
            $table->index(['company_id', 'effective_to'], 'eapa_company_to_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_attendance_policy_assignments ADD CONSTRAINT eapa_valid_period CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE employee_attendance_policy_assignments ADD CONSTRAINT eapa_valid_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_policy_assignments');

        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->dropUnique('attendance_policies_company_id_id_unique');
        });
    }
};
