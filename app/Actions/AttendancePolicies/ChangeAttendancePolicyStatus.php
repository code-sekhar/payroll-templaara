<?php

namespace App\Actions\AttendancePolicies;

use App\Enums\AttendancePolicyStatus;
use App\Models\AttendancePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeAttendancePolicyStatus
{
    public function handle(AttendancePolicy $attendancePolicy, User $user, AttendancePolicyStatus $status): AttendancePolicy
    {
        return DB::transaction(function () use ($attendancePolicy, $user, $status): AttendancePolicy {
            $attendancePolicy->update(['status' => $status, 'updated_by' => $user->id]);

            return $attendancePolicy->refresh();
        });
    }
}
