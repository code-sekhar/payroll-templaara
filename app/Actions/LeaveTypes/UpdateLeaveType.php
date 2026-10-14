<?php

namespace App\Actions\LeaveTypes;

use App\Enums\LeaveTypeStatus;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLeaveType
{
    /** @param array<string, mixed> $attributes */
    public function handle(LeaveType $leaveType, User $user, array $attributes): LeaveType
    {
        return DB::transaction(function () use ($leaveType, $user, $attributes): LeaveType {
            $lockedType = LeaveType::query()->lockForUpdate()->findOrFail($leaveType->id);

            if (! (bool) $attributes['allows_half_day'] && $lockedType->policies()
                ->where('status', LeaveTypeStatus::Active->value)
                ->where('allows_half_day', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'allows_half_day' => 'This leave type cannot disable half-day while an active policy uses that option.',
                ]);
            }

            $lockedType->update([...$attributes, 'updated_by' => $user->id]);

            return $lockedType->refresh();
        });
    }
}
