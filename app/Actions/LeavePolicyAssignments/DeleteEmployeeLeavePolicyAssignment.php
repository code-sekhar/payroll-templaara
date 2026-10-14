<?php

namespace App\Actions\LeavePolicyAssignments;

use App\Models\Company;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteEmployeeLeavePolicyAssignment
{
    public function handle(Company $company, EmployeeLeavePolicyAssignment $assignment, User $actor): void
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        DB::transaction(function () use ($company, $assignment, $actor): void {
            $locked = $company->employeeLeavePolicyAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $locked->update(['updated_by' => $actor->id]);
            $locked->delete();
        });
    }
}
