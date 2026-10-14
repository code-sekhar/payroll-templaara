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
        Schema::create('attendance_regularization_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('attendance_regularization_request_id');
            $table->string('action', 12);
            $table->unsignedBigInteger('attendance_punch_id')->nullable();
            $table->string('punch_type', 16)->nullable();
            $table->timestampTz('requested_punched_at', precision: 6)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign(['company_id', 'employee_id', 'attendance_regularization_request_id'], 'ari_company_employee_request_foreign')
                ->references(['company_id', 'employee_id', 'id'])->on('attendance_regularization_requests')->cascadeOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'ari_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id', 'attendance_punch_id'], 'ari_company_employee_punch_foreign')
                ->references(['company_id', 'employee_id', 'id'])->on('attendance_punches')->restrictOnDelete();
            $table->index('attendance_regularization_request_id', 'ari_request_index');
            $table->index('attendance_punch_id', 'ari_punch_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_regularization_items ADD CONSTRAINT ari_action_check CHECK (action IN ('add', 'replace', 'remove'))");
            DB::statement("ALTER TABLE attendance_regularization_items ADD CONSTRAINT ari_punch_type_check CHECK (punch_type IS NULL OR punch_type IN ('check_in', 'check_out'))");
            DB::statement("ALTER TABLE attendance_regularization_items ADD CONSTRAINT ari_action_shape_check CHECK ((action = 'add' AND attendance_punch_id IS NULL AND punch_type IS NOT NULL AND requested_punched_at IS NOT NULL AND timezone IS NOT NULL) OR (action = 'replace' AND attendance_punch_id IS NOT NULL AND punch_type IS NOT NULL AND requested_punched_at IS NOT NULL AND timezone IS NOT NULL) OR (action = 'remove' AND attendance_punch_id IS NOT NULL AND punch_type IS NULL AND requested_punched_at IS NULL AND timezone IS NULL))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_regularization_items');
    }
};
