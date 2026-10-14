<?php

namespace App\Data\Employees;

use App\Models\EmployeeInvitation;

class IssuedEmployeeInvitation
{
    public function __construct(
        public readonly EmployeeInvitation $invitation,
        public readonly string $rawToken,
        public readonly bool $wasReissued = false,
    ) {}
}
