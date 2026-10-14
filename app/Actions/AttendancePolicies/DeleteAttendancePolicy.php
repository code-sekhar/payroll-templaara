<?php

namespace App\Actions\AttendancePolicies;

use App\Models\AttendancePolicy;
use Illuminate\Support\Facades\DB;

class DeleteAttendancePolicy
{
    public function handle(AttendancePolicy $attendancePolicy): void
    {
        DB::transaction(fn () => $attendancePolicy->delete());
    }
}
