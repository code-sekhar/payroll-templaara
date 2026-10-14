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
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('grace_in_minutes')->default(0);
            $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->unsignedSmallInteger('late_after_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_after_minutes')->default(0);
            $table->unsignedSmallInteger('half_day_before_minutes')->default(0);
            $table->unsignedSmallInteger('absent_before_minutes')->default(0);
            $table->boolean('overtime_enabled')->default(false);
            $table->unsignedSmallInteger('overtime_after_minutes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_status_check CHECK (status IN ('active', 'inactive'))");

            foreach ([
                'grace_in_minutes', 'grace_out_minutes', 'late_after_minutes', 'early_leave_after_minutes',
                'half_day_before_minutes', 'absent_before_minutes', 'overtime_after_minutes',
            ] as $column) {
                DB::statement("ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_{$column}_range_check CHECK ({$column} BETWEEN 0 AND 1440)");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
    }
};
