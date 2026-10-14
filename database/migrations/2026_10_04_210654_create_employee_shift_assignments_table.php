<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'employees_company_id_id_unique');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'shifts_company_id_id_unique');
        });

        Schema::create('employee_shift_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('shift_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['company_id', 'employee_id'], 'esa_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'shift_id'], 'esa_company_shift_foreign')
                ->references(['company_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'effective_from'], 'esa_company_employee_from_index');
            $table->index(['company_id', 'shift_id'], 'esa_company_shift_index');
            $table->index(['company_id', 'status', 'effective_from'], 'esa_company_status_from_index');
            $table->index('effective_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_shift_assignments');

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropUnique('shifts_company_id_id_unique');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_id_id_unique');
        });
    }
};
