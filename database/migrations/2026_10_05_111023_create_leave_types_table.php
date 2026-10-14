<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->string('category', 24);
            $table->boolean('is_paid')->default(true);
            $table->boolean('allows_half_day')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'id'], 'leave_types_company_id_id_unique');
            $table->index(['company_id', 'status', 'category', 'sort_order', 'name'], 'leave_types_company_listing_index');
        });

        DB::statement('CREATE UNIQUE INDEX leave_types_company_name_live_unique ON leave_types (company_id, name) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX leave_types_company_code_live_unique ON leave_types (company_id, code) WHERE deleted_at IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_types ADD CONSTRAINT leave_types_category_check CHECK (category IN ('paid', 'unpaid', 'special', 'compensatory'))");
            DB::statement("ALTER TABLE leave_types ADD CONSTRAINT leave_types_status_check CHECK (status IN ('active', 'inactive'))");
            DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_types_sort_order_check CHECK (sort_order >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
