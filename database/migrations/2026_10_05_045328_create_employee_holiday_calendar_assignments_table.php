<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_holiday_calendar_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('holiday_calendar_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['company_id', 'employee_id'], 'ehca_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'holiday_calendar_id'], 'ehca_company_calendar_foreign')
                ->references(['company_id', 'id'])->on('holiday_calendars')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'status', 'effective_from'], 'ehca_company_employee_status_from_index');
            $table->index(['company_id', 'holiday_calendar_id', 'effective_from'], 'ehca_company_calendar_from_index');
            $table->index(['company_id', 'effective_to'], 'ehca_company_to_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_holiday_calendar_assignments ADD CONSTRAINT ehca_valid_period CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE employee_holiday_calendar_assignments ADD CONSTRAINT ehca_valid_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_holiday_calendar_assignments');
    }
};
