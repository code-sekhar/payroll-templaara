<?php

namespace App\Actions\Employees;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class CreateEmployee
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, array $attributes): Employee
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($company, $attributes): Employee {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            $number = $lockedCompany->employee_code_next_number;
            $lockedCompany->increment('employee_code_next_number');

            return $lockedCompany->employees()->create([
                ...$attributes,
                'employee_code' => $lockedCompany->employee_code_prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            ]);
        });
    }
}
