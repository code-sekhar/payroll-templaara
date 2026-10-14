<?php

namespace App\Actions\EmployeeAttendancePolicyAssignments;

use App\Models\Company;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteEmployeeAttendancePolicyAssignment
{
    public function handle(Company $company, EmployeeAttendancePolicyAssignment $assignment, User $actor): void
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        DB::transaction(function () use ($company, $assignment, $actor): void {
            $lockedAssignment = $company->employeeAttendancePolicyAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $lockedAssignment->update(['updated_by' => $actor->id]);
            $lockedAssignment->delete();
        });
    }
}
