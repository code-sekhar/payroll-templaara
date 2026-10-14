<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_policies DROP CONSTRAINT IF EXISTS leave_policies_carry_forward_check');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_carry_forward_check CHECK ((carry_forward_enabled = false AND max_carry_forward_days IS NULL) OR (carry_forward_enabled = true AND max_carry_forward_days IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_policies DROP CONSTRAINT IF EXISTS leave_policies_carry_forward_check');
            DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_carry_forward_check CHECK ((carry_forward_enabled = false AND max_carry_forward_days IS NULL) OR carry_forward_enabled = true)');
        }
    }
};
