<?php

namespace App\Actions\LeaveTypes;

use App\Enums\LeaveTypeStatus;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeLeaveTypeStatus
{
    public function handle(LeaveType $leaveType, User $user, LeaveTypeStatus $status): LeaveType
    {
        return DB::transaction(function () use ($leaveType, $user, $status): LeaveType {
            $lockedType = LeaveType::query()->lockForUpdate()->findOrFail($leaveType->id);
            $lockedType->update(['status' => $status, 'updated_by' => $user->id]);

            return $lockedType->refresh();
        });
    }
}
