<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_worksites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id'], 'attendance_worksites_company_id_unique');
            $table->index(['company_id', 'is_active', 'name']);
        });

        Schema::create('attendance_policy_worksites', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('attendance_policy_id');
            $table->unsignedBigInteger('attendance_worksite_id');
            $table->primary(['company_id', 'attendance_policy_id', 'attendance_worksite_id'], 'attendance_policy_worksites_primary');
            $table->foreign(['company_id', 'attendance_policy_id'], 'attendance_policy_worksites_policy_foreign')
                ->references(['company_id', 'id'])->on('attendance_policies')->cascadeOnDelete();
            $table->foreign(['company_id', 'attendance_worksite_id'], 'attendance_policy_worksites_worksite_foreign')
                ->references(['company_id', 'id'])->on('attendance_worksites')->cascadeOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_worksites ADD CONSTRAINT attendance_worksites_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE attendance_worksites ADD CONSTRAINT attendance_worksites_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE attendance_worksites ADD CONSTRAINT attendance_worksites_radius_check CHECK (radius_meters BETWEEN 1 AND 10000)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policy_worksites');
        Schema::dropIfExists('attendance_worksites');
    }
};
