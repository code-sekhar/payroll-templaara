<?php

namespace App\Models;

use App\Enums\EmployeeLeavePolicyAssignmentStatus;
use Database\Factories\EmployeeLeavePolicyAssignmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeLeavePolicyAssignment extends Model
{
    /** @use HasFactory<EmployeeLeavePolicyAssignmentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['employee_id', 'leave_type_id', 'leave_policy_id', 'effective_from', 'effective_to', 'status', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => EmployeeLeavePolicyAssignmentStatus::class,
        ];
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function balanceAccounts(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalanceAccount::class, 'employee_leave_policy_assignment_id');
    }

    public function scopeEffectiveOn(Builder $query, DateTimeInterface|string $date): Builder
    {
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $query->where('status', EmployeeLeavePolicyAssignmentStatus::Active->value)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $builder): Builder => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function scopeOverlappingPeriod(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string|null $to): Builder
    {
        $from = $from instanceof DateTimeInterface ? $from->format('Y-m-d') : $from;
        $to = $to instanceof DateTimeInterface ? $to->format('Y-m-d') : $to;

        return $query->where(fn (Builder $builder): Builder => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($to !== null, fn (Builder $builder): Builder => $builder->whereDate('effective_from', '<=', $to));
    }
}
