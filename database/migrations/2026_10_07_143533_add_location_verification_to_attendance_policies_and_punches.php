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
            $table->string('location_mode', 24)->default('disabled');
        });

        Schema::table('attendance_punches', function (Blueprint $table): void {
            $table->string('location_mode', 24)->nullable();
            $table->string('location_result', 24)->nullable();
            $table->unsignedBigInteger('worksite_id')->nullable();
            $table->string('worksite_name_snapshot', 160)->nullable();
            $table->decimal('location_distance_meters', 10, 2)->nullable();
            $table->unsignedInteger('worksite_radius_meters_snapshot')->nullable();
            $table->foreign(['company_id', 'worksite_id'], 'attendance_punches_company_worksite_foreign')
                ->references(['company_id', 'id'])->on('attendance_worksites')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_location_mode_check CHECK (location_mode IN ('disabled', 'capture', 'required', 'geofence_required'))");
            DB::statement("ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_location_mode_check CHECK (location_mode IS NULL OR location_mode IN ('disabled', 'capture', 'required', 'geofence_required'))");
            DB::statement("ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_location_result_check CHECK (location_result IS NULL OR location_result IN ('not_requested', 'not_captured', 'captured', 'provided', 'verified'))");
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_location_context_check CHECK ((worksite_id IS NULL AND worksite_name_snapshot IS NULL AND location_distance_meters IS NULL AND worksite_radius_meters_snapshot IS NULL) OR (worksite_id IS NOT NULL AND worksite_name_snapshot IS NOT NULL AND location_distance_meters IS NOT NULL AND worksite_radius_meters_snapshot IS NOT NULL))');
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_verified_has_worksite_check CHECK (location_result <> \'verified\' OR worksite_id IS NOT NULL)');
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_location_distance_check CHECK (location_distance_meters IS NULL OR location_distance_meters >= 0)');
            DB::statement('ALTER TABLE attendance_punches ADD CONSTRAINT attendance_punches_worksite_radius_check CHECK (worksite_radius_meters_snapshot IS NULL OR worksite_radius_meters_snapshot BETWEEN 1 AND 10000)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_location_context_check');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_verified_has_worksite_check');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_location_distance_check');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_worksite_radius_check');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_location_result_check');
            DB::statement('ALTER TABLE attendance_punches DROP CONSTRAINT IF EXISTS attendance_punches_location_mode_check');
            DB::statement('ALTER TABLE attendance_policies DROP CONSTRAINT IF EXISTS attendance_policies_location_mode_check');
        }

        Schema::table('attendance_punches', function (Blueprint $table): void {
            $table->dropForeign('attendance_punches_company_worksite_foreign');
            $table->dropColumn([
                'location_mode', 'location_result', 'worksite_id', 'worksite_name_snapshot',
                'location_distance_meters', 'worksite_radius_meters_snapshot',
            ]);
        });

        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->dropColumn('location_mode');
        });
    }
};
