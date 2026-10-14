<?php

namespace App\Actions\EmployeeHolidayCalendarAssignments;

use App\Models\Company;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteEmployeeHolidayCalendarAssignment
{
    public function handle(Company $company, EmployeeHolidayCalendarAssignment $assignment, User $actor): void
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        DB::transaction(function () use ($company, $assignment, $actor): void {
            $lockedAssignment = $company->employeeHolidayCalendarAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $lockedAssignment->update(['updated_by' => $actor->id]);
            $lockedAssignment->delete();
        });
    }
}
