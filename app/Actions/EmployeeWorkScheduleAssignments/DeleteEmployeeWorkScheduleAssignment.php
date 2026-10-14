<?php

namespace App\Actions\EmployeeWorkScheduleAssignments;

use App\Models\Company;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteEmployeeWorkScheduleAssignment
{
    public function handle(Company $company, EmployeeWorkScheduleAssignment $assignment, User $actor): void
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        DB::transaction(function () use ($company, $assignment, $actor): void {
            $lockedAssignment = $company->employeeWorkScheduleAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $lockedAssignment->update(['updated_by' => $actor->id]);
            $lockedAssignment->delete();
        });
    }
}
