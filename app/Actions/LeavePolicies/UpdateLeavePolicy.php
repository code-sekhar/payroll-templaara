<?php

namespace App\Actions\LeavePolicies;

use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLeavePolicy
{
    /** @param array<string, mixed> $attributes */
    public function handle(LeavePolicy $leavePolicy, User $user, array $attributes): LeavePolicy
    {
        return DB::transaction(function () use ($leavePolicy, $user, $attributes): LeavePolicy {
            $lockedPolicy = LeavePolicy::query()->lockForUpdate()->findOrFail($leavePolicy->id);
            $leaveType = LeaveType::query()
                ->where('company_id', $lockedPolicy->company_id)
                ->lockForUpdate()
                ->findOrFail($attributes['leave_type_id']);
            $isChangingToActiveConfiguration = $lockedPolicy->status !== LeavePolicyStatus::Active
                || (int) $lockedPolicy->leave_type_id !== (int) $leaveType->id;

            if (($attributes['status'] ?? $lockedPolicy->status->value) === LeavePolicyStatus::Active->value
                && $isChangingToActiveConfiguration
                && $leaveType->status !== LeaveTypeStatus::Active) {
                throw ValidationException::withMessages(['leave_type_id' => 'An active policy must use an active leave type.']);
            }

            if (($attributes['allows_half_day'] ?? false) && ! $leaveType->allows_half_day) {
                throw ValidationException::withMessages(['allows_half_day' => 'This leave type does not allow half-day leave.']);
            }

            $lockedPolicy->update([...$attributes, 'updated_by' => $user->id]);

            return $lockedPolicy->refresh();
        });
    }
}
