<?php

namespace App\Models;

use App\Enums\LeaveLedgerTransactionType;
use Database\Factories\LeaveLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeaveLedgerEntry extends Model
{
    /** @use HasFactory<LeaveLedgerEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'leave_balance_account_id', 'employee_id', 'leave_type_id', 'leave_policy_id',
        'employee_leave_policy_assignment_id', 'transaction_type', 'amount', 'effective_date', 'description',
        'reference_type', 'reference_id', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => LeaveLedgerTransactionType::class,
            'amount' => 'decimal:2',
            'effective_date' => 'date',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Leave ledger entries are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Leave ledger entries are append-only.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeaveBalanceAccount::class, 'leave_balance_account_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function leavePolicy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class)->withTrashed();
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeavePolicyAssignment::class, 'employee_leave_policy_assignment_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
