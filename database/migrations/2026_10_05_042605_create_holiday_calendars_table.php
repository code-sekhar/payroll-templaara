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
        Schema::create('holiday_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name', 'year'], 'holiday_calendars_company_name_year_unique');
            $table->unique(['company_id', 'id'], 'holiday_calendars_company_id_id_unique');
            $table->index(['company_id', 'year', 'status', 'deleted_at'], 'holiday_calendars_company_year_status_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE holiday_calendars ADD CONSTRAINT holiday_calendars_valid_year CHECK (year BETWEEN 1900 AND 2200)');
            DB::statement("ALTER TABLE holiday_calendars ADD CONSTRAINT holiday_calendars_valid_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holiday_calendars');
    }
};
