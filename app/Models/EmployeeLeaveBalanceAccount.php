<?php

namespace App\Models;

use App\Enums\LeaveBalanceAccountStatus;
use Database\Factories\EmployeeLeaveBalanceAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmployeeLeaveBalanceAccount extends Model
{
    /** @use HasFactory<EmployeeLeaveBalanceAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id',
        'period_start', 'period_end', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => LeaveBalanceAccountStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (EmployeeLeaveBalanceAccount $account): void {
            foreach (['company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id', 'period_start', 'period_end', 'created_by'] as $immutableColumn) {
                if ($account->isDirty($immutableColumn)) {
                    throw new LogicException('Leave balance account ownership and period are immutable.');
                }
            }

            if ($account->getRawOriginal('status') === LeaveBalanceAccountStatus::Closed->value
                && $account->status === LeaveBalanceAccountStatus::Open) {
                throw new LogicException('Closed leave balance accounts cannot be reopened.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Leave balance accounts cannot be deleted.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class, 'leave_balance_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', LeaveBalanceAccountStatus::Open->value);
    }
}
