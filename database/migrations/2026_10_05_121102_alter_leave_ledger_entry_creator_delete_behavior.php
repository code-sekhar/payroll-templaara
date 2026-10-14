<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_created_by_foreign');
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_created_by_foreign');
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
    }
};
