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
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'work_schedules_company_id_id_unique');
            $table->index(['company_id', 'status', 'deleted_at']);
        });

        Schema::create('work_schedule_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('work_schedule_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('day_type', 16);
            $table->timestamps();
            $table->foreign(['company_id', 'work_schedule_id'], 'work_schedule_days_company_schedule_foreign')
                ->references(['company_id', 'id'])->on('work_schedules')->restrictOnDelete();
            $table->foreign(['company_id', 'shift_id'], 'work_schedule_days_company_shift_foreign')
                ->references(['company_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->unique(['company_id', 'work_schedule_id', 'day_of_week'], 'work_schedule_days_unique_day');
            $table->index(['company_id', 'work_schedule_id']);
            $table->index(['company_id', 'shift_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedule_days');
        Schema::dropIfExists('work_schedules');
    }
};
