<?php

namespace App\Actions\LeaveTypes;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteLeaveType
{
    public function handle(LeaveType $leaveType, User $user): void
    {
        DB::transaction(function () use ($leaveType, $user): void {
            $lockedType = LeaveType::query()->lockForUpdate()->findOrFail($leaveType->id);

            if ($lockedType->policies()->exists()) {
                throw ValidationException::withMessages([
                    'leave_type' => 'This leave type cannot be archived while non-deleted leave policies reference it.',
                ]);
            }

            $lockedType->update(['updated_by' => $user->id]);
            $lockedType->delete();
        });
    }
}
