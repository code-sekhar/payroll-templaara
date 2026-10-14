<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularization_requests', function (Blueprint $table): void {
            $table->unique(['company_id', 'employee_id', 'attendance_date', 'id'], 'arr_company_employee_date_id_unique');
        });

        Schema::table('attendance_regularization_items', function (Blueprint $table): void {
            $table->unique(['company_id', 'employee_id', 'attendance_regularization_request_id', 'id'], 'ari_company_employee_request_item_unique');
        });

        Schema::create('attendance_punch_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            $table->unsignedBigInteger('attendance_regularization_request_id');
            $table->unsignedBigInteger('attendance_regularization_item_id');
            $table->string('action', 12);
            $table->unsignedBigInteger('original_attendance_punch_id')->nullable();
            $table->string('effective_punch_type', 16)->nullable();
            $table->timestampTz('effective_punched_at', precision: 6)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at', precision: 6);
            $table->timestampTz('created_at', precision: 6);

            $table->unique('attendance_regularization_item_id', 'apc_regularization_item_unique');
            $table->unique(['company_id', 'employee_id', 'original_attendance_punch_id'], 'apc_original_punch_unique');
            $table->foreign(['company_id', 'employee_id'], 'apc_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'attendance_date', 'attendance_regularization_request_id'], 'apc_company_employee_date_request_foreign')
                ->references(['company_id', 'employee_id', 'attendance_date', 'id'])->on('attendance_regularization_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'attendance_regularization_request_id', 'attendance_regularization_item_id'], 'apc_company_employee_request_item_foreign')
                ->references(['company_id', 'employee_id', 'attendance_regularization_request_id', 'id'])->on('attendance_regularization_items')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'original_attendance_punch_id'], 'apc_company_employee_punch_foreign')
                ->references(['company_id', 'employee_id', 'id'])->on('attendance_punches')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'attendance_date'], 'apc_company_employee_date_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_punch_corrections ADD CONSTRAINT apc_action_check CHECK (action IN ('add', 'replace', 'remove'))");
            DB::statement("ALTER TABLE attendance_punch_corrections ADD CONSTRAINT apc_punch_type_check CHECK (effective_punch_type IS NULL OR effective_punch_type IN ('check_in', 'check_out'))");
            DB::statement("ALTER TABLE attendance_punch_corrections ADD CONSTRAINT apc_action_shape_check CHECK ((action = 'add' AND original_attendance_punch_id IS NULL AND effective_punch_type IS NOT NULL AND effective_punched_at IS NOT NULL AND timezone IS NOT NULL) OR (action = 'replace' AND original_attendance_punch_id IS NOT NULL AND effective_punch_type IS NOT NULL AND effective_punched_at IS NOT NULL AND timezone IS NOT NULL) OR (action = 'remove' AND original_attendance_punch_id IS NOT NULL AND effective_punch_type IS NULL AND effective_punched_at IS NULL AND timezone IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punch_corrections');

        Schema::table('attendance_regularization_items', function (Blueprint $table): void {
            $table->dropUnique('ari_company_employee_request_item_unique');
        });

        Schema::table('attendance_regularization_requests', function (Blueprint $table): void {
            $table->dropUnique('arr_company_employee_date_id_unique');
        });
    }
};
