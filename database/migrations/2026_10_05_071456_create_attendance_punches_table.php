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
        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->string('punch_type', 16);
            $table->timestampTz('punched_at', precision: 6);
            $table->string('timezone', 64);
            $table->string('source', 16);
            $table->string('device_identifier')->nullable();
            $table->string('external_reference')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('location_accuracy_meters', 10, 2)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['company_id', 'employee_id'], 'attendance_punches_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();

            $table->index(['company_id', 'punched_at'], 'attendance_punches_company_punched_at_index');
            $table->index(['company_id', 'employee_id', 'punched_at'], 'attendance_punches_company_employee_punched_at_index');
            $table->index(['company_id', 'punch_type', 'punched_at'], 'attendance_punches_company_type_punched_at_index');
            $table->index(['company_id', 'source', 'punched_at'], 'attendance_punches_company_source_punched_at_index');
            $table->unique(['company_id', 'source', 'external_reference'], 'attendance_punches_external_reference_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_type_check CHECK (punch_type IN ('check_in', 'check_out'))");
            DB::statement("ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_source_check CHECK (source IN ('web', 'mobile', 'manual', 'biometric', 'api', 'import'))");
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_latitude_check CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_longitude_check CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_accuracy_check CHECK (location_accuracy_meters IS NULL OR location_accuracy_meters >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
