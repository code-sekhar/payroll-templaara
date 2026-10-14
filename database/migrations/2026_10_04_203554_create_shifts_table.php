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
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_duration_minutes')->default(0);
            $table->unsignedInteger('grace_period_minutes')->default(0);
            $table->boolean('is_overnight')->default(false);
            $table->string('status', 16)->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
