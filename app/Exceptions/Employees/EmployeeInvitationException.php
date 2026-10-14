<?php

namespace App\Exceptions\Employees;

use RuntimeException;

final class EmployeeInvitationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Employee invitation operation failed: {$reason}.");
    }
}
