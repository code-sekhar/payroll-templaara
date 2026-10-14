<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('leave_balance_account_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_policy_id');
            $table->unsignedBigInteger('employee_leave_policy_assignment_id');
            $table->string('transaction_type', 24);
            $table->decimal('amount', 10, 2);
            $table->date('effective_date');
            $table->text('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(
                ['company_id', 'leave_balance_account_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'],
                'lle_account_ownership_foreign'
            )->references(['company_id', 'id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id'])
                ->on('employee_leave_balance_accounts')->restrictOnDelete();
            $table->index(['company_id', 'leave_balance_account_id', 'effective_date', 'created_at', 'id'], 'lle_account_history_index');
            $table->index(['company_id', 'employee_id', 'effective_date'], 'lle_employee_date_index');
        });

        DB::statement("CREATE UNIQUE INDEX lle_one_opening_per_account_unique ON leave_ledger_entries (leave_balance_account_id) WHERE transaction_type = 'opening_balance'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_ledger_entries ADD CONSTRAINT lle_valid_type CHECK (transaction_type IN ('opening_balance', 'entitlement', 'accrual', 'carry_forward', 'adjustment', 'leave_used', 'leave_reversed', 'expiry'))");
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT lle_nonzero_amount CHECK (amount <> 0)');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_leave_ledger_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Leave ledger entries are append-only.' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER leave_ledger_entries_no_update
                BEFORE UPDATE OR DELETE ON leave_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_leave_ledger_mutation();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER leave_ledger_entries_no_update BEFORE UPDATE ON leave_ledger_entries BEGIN SELECT RAISE(ABORT, 'Leave ledger entries are append-only.'); END");
            DB::statement("CREATE TRIGGER leave_ledger_entries_no_delete BEFORE DELETE ON leave_ledger_entries BEGIN SELECT RAISE(ABORT, 'Leave ledger entries are append-only.'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS leave_ledger_entries_no_update ON leave_ledger_entries');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_leave_ledger_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS leave_ledger_entries_no_update');
            DB::statement('DROP TRIGGER IF EXISTS leave_ledger_entries_no_delete');
        }

        Schema::dropIfExists('leave_ledger_entries');
    }
};
