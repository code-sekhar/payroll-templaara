<?php

namespace App\Actions\Employees;

use App\Models\Employee;

class UpdateEmployee
{
    /** @param array<string, mixed> $attributes */
    public function handle(Employee $employee, array $attributes): Employee
    {
        unset($attributes['user_id']);
        $employee->update($attributes);

        return $employee;
    }
}
