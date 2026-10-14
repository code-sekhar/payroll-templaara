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
        $duplicateExists = DB::table('leave_ledger_entries')
            ->select('reference_type', 'reference_id')
            ->selectRaw('COUNT(*) AS reversal_count')
            ->where('transaction_type', 'leave_reversed')
            ->where('reference_type', 'App\\Models\\LeaveLedgerEntry')
            ->whereNotNull('reference_id')
            ->groupBy('reference_type', 'reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateExists) {
            throw new RuntimeException('Cannot install the approved-leave reversal uniqueness index: duplicate reversal references exist. Investigate the affected accounting history before retrying; no ledger rows were changed.');
        }

        DB::statement("CREATE UNIQUE INDEX leave_ledger_unique_approved_reversal_reference ON leave_ledger_entries (reference_type, reference_id) WHERE transaction_type = 'leave_reversed' AND reference_type = 'App\\Models\\LeaveLedgerEntry' AND reference_id IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS leave_ledger_unique_approved_reversal_reference');
    }
};
