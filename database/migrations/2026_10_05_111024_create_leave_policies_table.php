<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('leave_type_id');
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->decimal('entitlement_days', 8, 2)->default(0);
            $table->string('accrual_method', 16)->default('none');
            $table->string('accrual_frequency', 16)->default('none');
            $table->boolean('carry_forward_enabled')->default(false);
            $table->decimal('max_carry_forward_days', 8, 2)->nullable();
            $table->decimal('max_balance_days', 8, 2)->nullable();
            $table->boolean('allow_negative_balance')->default(false);
            $table->decimal('max_negative_balance_days', 8, 2)->nullable();
            $table->decimal('minimum_request_days', 8, 2)->default(1);
            $table->decimal('maximum_request_days', 8, 2)->nullable();
            $table->unsignedSmallInteger('minimum_notice_days')->default(0);
            $table->boolean('allows_half_day')->default(false);
            $table->boolean('requires_reason')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['company_id', 'leave_type_id'], 'leave_policies_company_type_foreign')
                ->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'leave_policies_company_id_id_unique');
            $table->index(['company_id', 'status', 'name'], 'leave_policies_company_status_name_index');
            $table->index(['company_id', 'leave_type_id', 'deleted_at'], 'leave_policies_company_type_index');
            $table->index(['company_id', 'accrual_method', 'status'], 'leave_policies_company_accrual_index');
        });

        DB::statement('CREATE UNIQUE INDEX leave_policies_company_name_live_unique ON leave_policies (company_id, name) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX leave_policies_company_code_live_unique ON leave_policies (company_id, code) WHERE deleted_at IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_status_check CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_accrual_method_check CHECK (accrual_method IN ('none', 'upfront', 'accrual'))");
            DB::statement("ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_accrual_frequency_check CHECK (accrual_frequency IN ('none', 'monthly', 'quarterly', 'yearly'))");
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_accrual_pair_check CHECK ((accrual_method = \'accrual\' AND accrual_frequency <> \'none\') OR (accrual_method <> \'accrual\' AND accrual_frequency = \'none\'))');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_day_quantities_check CHECK (entitlement_days >= 0 AND (max_carry_forward_days IS NULL OR max_carry_forward_days >= 0) AND (max_balance_days IS NULL OR max_balance_days >= 0) AND (max_negative_balance_days IS NULL OR max_negative_balance_days >= 0) AND minimum_request_days > 0 AND (maximum_request_days IS NULL OR maximum_request_days >= minimum_request_days))');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_carry_forward_check CHECK ((carry_forward_enabled = false AND max_carry_forward_days IS NULL) OR carry_forward_enabled = true)');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_negative_balance_check CHECK ((allow_negative_balance = false AND max_negative_balance_days IS NULL) OR (allow_negative_balance = true AND max_negative_balance_days > 0))');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_request_half_day_check CHECK (allows_half_day = true OR minimum_request_days >= 1)');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_notice_check CHECK (minimum_notice_days >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
    }
};
