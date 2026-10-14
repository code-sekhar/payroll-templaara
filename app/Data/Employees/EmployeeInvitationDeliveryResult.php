<?php

namespace App\Data\Employees;

final readonly class EmployeeInvitationDeliveryResult
{
    public function __construct(
        public string $email,
        public bool $wasReissued,
        public bool $emailDelivered,
    ) {}
}
