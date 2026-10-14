<?php

namespace App\Models;

use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'description', 'category', 'is_paid', 'allows_half_day', 'status', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'category' => 'paid',
        'is_paid' => true,
        'allows_half_day' => false,
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => LeaveTypeCategory::class,
            'is_paid' => 'boolean',
            'allows_half_day' => 'boolean',
            'status' => LeaveTypeStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeLeavePolicyAssignment::class);
    }

    public function balanceAccounts(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalanceAccount::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
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
