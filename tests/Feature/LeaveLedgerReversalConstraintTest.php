<?php

use App\Enums\LeaveLedgerTransactionType;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use Illuminate\Database\QueryException;

/** @return array{LeaveLedgerEntry, array<string, mixed>} */
function leaveReversalConstraintFixture(): array
{
    $usage = LeaveLedgerEntry::factory()->create([
        'transaction_type' => LeaveLedgerTransactionType::LeaveUsed,
        'amount' => '-1.50',
        'reference_type' => LeaveRequest::class,
        'reference_id' => 9001,
    ]);

    $reversalAttributes = [
        'company_id' => $usage->company_id,
        'leave_balance_account_id' => $usage->leave_balance_account_id,
        'employee_id' => $usage->employee_id,
        'leave_type_id' => $usage->leave_type_id,
        'leave_policy_id' => $usage->leave_policy_id,
        'employee_leave_policy_assignment_id' => $usage->employee_leave_policy_assignment_id,
        'transaction_type' => LeaveLedgerTransactionType::LeaveReversed,
        'amount' => '1.50',
        'effective_date' => $usage->effective_date,
        'description' => 'Approved leave reversal',
        'reference_type' => LeaveLedgerEntry::class,
        'reference_id' => $usage->id,
        'created_by' => null,
        'created_at' => now(),
    ];

    return [$usage, $reversalAttributes];
}

test('database allows one approved leave reversal for an original usage entry', function () {
    [$usage, $reversalAttributes] = leaveReversalConstraintFixture();

    $reversal = LeaveLedgerEntry::query()->create($reversalAttributes);

    expect($reversal->exists)->toBeTrue()
        ->and($reversal->transaction_type)->toBe(LeaveLedgerTransactionType::LeaveReversed)
        ->and($reversal->amount)->toBe('1.50')
        ->and($reversal->reference_id)->toBe($usage->id);
});

test('database rejects a second approved leave reversal for the same usage entry', function () {
    [, $reversalAttributes] = leaveReversalConstraintFixture();
    LeaveLedgerEntry::query()->create($reversalAttributes);

    expect(fn () => LeaveLedgerEntry::query()->create($reversalAttributes))
        ->toThrow(QueryException::class);
});

test('different usage entries can each have one reversal', function () {
    [$firstUsage, $firstReversalAttributes] = leaveReversalConstraintFixture();
    [$secondUsage, $secondReversalAttributes] = leaveReversalConstraintFixture();
    LeaveLedgerEntry::query()->create($firstReversalAttributes);
    LeaveLedgerEntry::query()->create(array_merge($secondReversalAttributes, ['reference_id' => $secondUsage->id]));

    expect(LeaveLedgerEntry::query()
        ->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)
        ->where('reference_type', LeaveLedgerEntry::class)
        ->count())->toBe(2)
        ->and($firstUsage->id)->not->toBe($secondUsage->id);
});

test('partial index does not constrain unrelated reference types transaction types or null references', function () {
    [, $reversalAttributes] = leaveReversalConstraintFixture();
    LeaveLedgerEntry::query()->create($reversalAttributes);

    LeaveLedgerEntry::factory()->create(array_merge($reversalAttributes, [
        'transaction_type' => LeaveLedgerTransactionType::LeaveReversed,
        'reference_type' => LeaveRequest::class,
    ]));
    LeaveLedgerEntry::factory()->create(array_merge($reversalAttributes, [
        'transaction_type' => LeaveLedgerTransactionType::Adjustment,
        'reference_type' => LeaveLedgerEntry::class,
    ]));
    LeaveLedgerEntry::factory()->create([
        'transaction_type' => LeaveLedgerTransactionType::Accrual,
        'reference_type' => null,
        'reference_id' => null,
    ]);

    expect(LeaveLedgerEntry::query()->count())->toBe(5);
});
