<?php

namespace App\Services\Employees;

final readonly class EmployeeProfileData
{
    /**
     * @param  array{
     *     identity: array{name: string, employeeCode: string},
     *     personal: array{email: ?string, phone: ?string},
     *     employment: array{department: ?string, designation: ?string, branch: ?string, type: string, status: string, joiningDate: ?string, manager: ?string},
     *     workAssignment: array{shift: ?string, workSchedule: ?string},
     *     account: array{loginEmail: ?string, connected: bool}
     * }  $props
     */
    public function __construct(public array $props) {}
}
