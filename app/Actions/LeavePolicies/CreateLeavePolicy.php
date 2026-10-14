<?php

namespace App\Actions\LeavePolicies;

use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateLeavePolicy
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $user, array $attributes): LeavePolicy
    {
        return DB::transaction(function () use ($company, $user, $attributes): LeavePolicy {
            $leaveType = LeaveType::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($attributes['leave_type_id']);

            $this->assertCompatibleType($leaveType, $attributes);

            return $company->leavePolicies()->create([
                ...$attributes,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function assertCompatibleType(LeaveType $leaveType, array $attributes): void
    {
        if (($attributes['status'] ?? LeavePolicyStatus::Active->value) === LeavePolicyStatus::Active->value
            && $leaveType->status !== LeaveTypeStatus::Active) {
            throw ValidationException::withMessages(['leave_type_id' => 'An active policy must use an active leave type.']);
        }

        if (($attributes['allows_half_day'] ?? false) && ! $leaveType->allows_half_day) {
            throw ValidationException::withMessages(['allows_half_day' => 'This leave type does not allow half-day leave.']);
        }
    }
}
