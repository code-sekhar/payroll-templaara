<?php

namespace App\Actions\LeaveTypes;

use App\Models\Company;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateLeaveType
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $user, array $attributes): LeaveType
    {
        return DB::transaction(fn (): LeaveType => $company->leaveTypes()->create([
            ...$attributes,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));
    }
}
