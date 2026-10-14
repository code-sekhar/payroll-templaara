<?php

namespace App\Data\Employees;

use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;

class AcceptedEmployeeInvitation
{
    public function __construct(
        public readonly EmployeeInvitation $invitation,
        public readonly User $user,
        public readonly Employee $employee,
        public readonly CompanyUser $membership,
        public readonly bool $newUser,
        public readonly bool $alreadyAccepted,
    ) {}
}
