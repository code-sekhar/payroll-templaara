<?php

namespace App\Models;

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeavePolicyStatus;
use Database\Factories\LeavePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeavePolicy extends Model
{
    /** @use HasFactory<LeavePolicyFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'leave_type_id', 'name', 'code', 'description', 'status', 'entitlement_days', 'accrual_method', 'accrual_frequency',
        'carry_forward_enabled', 'max_carry_forward_days', 'max_balance_days', 'allow_negative_balance',
        'max_negative_balance_days', 'minimum_request_days', 'maximum_request_days', 'minimum_notice_days',
        'allows_half_day', 'requires_reason', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
        'entitlement_days' => 0,
        'accrual_method' => 'none',
        'accrual_frequency' => 'none',
        'carry_forward_enabled' => false,
        'allow_negative_balance' => false,
        'minimum_request_days' => 1,
        'minimum_notice_days' => 0,
        'allows_half_day' => false,
        'requires_reason' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => LeavePolicyStatus::class,
            'entitlement_days' => 'decimal:2',
            'accrual_method' => LeaveAccrualMethod::class,
            'accrual_frequency' => LeaveAccrualFrequency::class,
            'carry_forward_enabled' => 'boolean',
            'max_carry_forward_days' => 'decimal:2',
            'max_balance_days' => 'decimal:2',
            'allow_negative_balance' => 'boolean',
            'max_negative_balance_days' => 'decimal:2',
            'minimum_request_days' => 'decimal:2',
            'maximum_request_days' => 'decimal:2',
            'minimum_notice_days' => 'integer',
            'allows_half_day' => 'boolean',
            'requires_reason' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeLeavePolicyAssignment::class);
    }

    public function balanceAccounts(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalanceAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
