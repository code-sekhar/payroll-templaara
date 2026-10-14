<?php

namespace App\Actions\LeavePolicies;

use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeLeavePolicyStatus
{
    public function handle(LeavePolicy $leavePolicy, User $user, LeavePolicyStatus $status): LeavePolicy
    {
        return DB::transaction(function () use ($leavePolicy, $user, $status): LeavePolicy {
            $lockedPolicy = LeavePolicy::query()->lockForUpdate()->findOrFail($leavePolicy->id);
            if ($status === LeavePolicyStatus::Active) {
                $leaveType = LeaveType::query()
                    ->where('company_id', $lockedPolicy->company_id)
                    ->lockForUpdate()
                    ->findOrFail($lockedPolicy->leave_type_id);

                if ($leaveType->status !== LeaveTypeStatus::Active) {
                    throw ValidationException::withMessages(['leave_type_id' => 'An active policy must use an active leave type.']);
                }
            }

            $lockedPolicy->update(['status' => $status, 'updated_by' => $user->id]);

            return $lockedPolicy->refresh();
        });
    }
}
