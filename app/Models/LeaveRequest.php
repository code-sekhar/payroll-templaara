<?php

namespace App\Models;

use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
use App\Services\Leave\LeaveAmount;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id',
        'leave_balance_account_id', 'start_date', 'end_date', 'requested_days', 'duration', 'status', 'reason',
        'employee_note', 'requested_by', 'requested_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'requested_days' => 'decimal:2',
            'status' => LeaveRequestStatus::class, 'requested_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LeaveRequest $request): void {
            foreach ([
                'company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id',
                'leave_balance_account_id', 'start_date', 'end_date', 'requested_days', 'duration', 'reason',
                'employee_note', 'requested_by', 'requested_at',
            ] as $column) {
                if ($request->isDirty($column)) {
                    throw new LogicException('Leave request details are immutable after submission.');
                }
            }

            if ($request->isDirty('status')) {
                $originalStatus = $request->getRawOriginal('status');

                if ($originalStatus === LeaveRequestStatus::Pending->value && $request->status === LeaveRequestStatus::Cancelled) {
                    if (! $request->isDirty('cancelled_by') || ! $request->isDirty('cancelled_at') || ! $request->isDirty('cancellation_reason')
                        || ! $request->cancelled_by || ! $request->cancelled_at || trim((string) $request->cancellation_reason) === ''
                        || $request->isDirty('reviewed_by') || $request->isDirty('reviewed_at') || $request->isDirty('review_note')) {
                        throw new LogicException('Only a pending request may be cancelled with complete cancellation audit fields.');
                    }
                } elseif ($originalStatus === LeaveRequestStatus::Approved->value && $request->status === LeaveRequestStatus::Cancelled) {
                    if (! $request->isDirty('cancelled_by') || ! $request->isDirty('cancelled_at') || ! $request->isDirty('cancellation_reason')
                        || ! $request->cancelled_by || ! $request->cancelled_at || trim((string) $request->cancellation_reason) === ''
                        || $request->isDirty('reviewed_by') || $request->isDirty('reviewed_at') || $request->isDirty('review_note')
                        || ! $request->reviewed_by || ! $request->reviewed_at
                        || ! $request->hasExactApprovedLeaveReversal()) {
                        throw new LogicException('An approved request may be cancelled only after its exact leave usage reversal is appended.');
                    }
                } elseif (in_array($request->status, [LeaveRequestStatus::Approved, LeaveRequestStatus::Rejected], true)) {
                    if ($originalStatus !== LeaveRequestStatus::Pending->value) {
                        throw new LogicException('Only a pending request may be reviewed.');
                    }
                    if (! $request->isDirty('reviewed_by') || ! $request->isDirty('reviewed_at') || ! $request->reviewed_by || ! $request->reviewed_at
                        || $request->isDirty('cancelled_by') || $request->isDirty('cancelled_at') || $request->isDirty('cancellation_reason')
                        || ($request->status === LeaveRequestStatus::Rejected && mb_strlen(trim((string) $request->review_note)) < 5)) {
                        throw new LogicException('A reviewed request requires complete review audit fields.');
                    }
                } else {
                    throw new LogicException('This leave request transition is not supported.');
                }
            } elseif ($request->isDirty('cancelled_by') || $request->isDirty('cancelled_at') || $request->isDirty('cancellation_reason')
                || $request->isDirty('reviewed_by') || $request->isDirty('reviewed_at') || $request->isDirty('review_note')) {
                throw new LogicException('Review audit fields may only change during a pending request transition.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Leave requests cannot be deleted.');
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

    public function balanceAccount(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeaveBalanceAccount::class, 'leave_balance_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class);
    }

    private function hasExactApprovedLeaveReversal(): bool
    {
        $usageEntries = LeaveLedgerEntry::query()
            ->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)
            ->where('reference_type', self::class)
            ->where('reference_id', $this->getKey())
            ->get();

        if ($usageEntries->count() !== 1) {
            return false;
        }

        $usage = $usageEntries->first();
        $reversals = LeaveLedgerEntry::query()
            ->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)
            ->where('reference_type', LeaveLedgerEntry::class)
            ->where('reference_id', $usage->getKey())
            ->get();

        return $reversals->count() === 1
            && (int) $usage->company_id === (int) $this->company_id
            && (int) $usage->employee_id === (int) $this->employee_id
            && (int) $usage->leave_balance_account_id === (int) $this->leave_balance_account_id
            && (int) $usage->leave_type_id === (int) $this->leave_type_id
            && (int) $usage->leave_policy_id === (int) $this->leave_policy_id
            && (int) $usage->employee_leave_policy_assignment_id === (int) $this->employee_leave_policy_assignment_id
            && LeaveAmount::toCents((string) $usage->amount) === -LeaveAmount::toCents((string) $this->requested_days)
            && (int) $reversals->first()->company_id === (int) $usage->company_id
            && (int) $reversals->first()->employee_id === (int) $usage->employee_id
            && (int) $reversals->first()->leave_balance_account_id === (int) $usage->leave_balance_account_id
            && (int) $reversals->first()->leave_type_id === (int) $usage->leave_type_id
            && (int) $reversals->first()->leave_policy_id === (int) $usage->leave_policy_id
            && (int) $reversals->first()->employee_leave_policy_assignment_id === (int) $usage->employee_leave_policy_assignment_id
            && (int) $reversals->first()->reference_id === (int) $usage->getKey()
            && $reversals->first()->reference_type === LeaveLedgerEntry::class
            && LeaveAmount::toCents((string) $usage->amount) < 0
            && LeaveAmount::toCents((string) $reversals->first()->amount) === -LeaveAmount::toCents((string) $usage->amount);
    }
}
