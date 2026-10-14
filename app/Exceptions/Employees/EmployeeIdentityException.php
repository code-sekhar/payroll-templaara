<?php

namespace App\Exceptions\Employees;

use RuntimeException;

final class EmployeeIdentityException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Employee identity operation failed: {$reason}.");
    }
}
