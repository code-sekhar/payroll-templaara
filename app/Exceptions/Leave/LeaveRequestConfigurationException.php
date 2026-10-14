<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class LeaveRequestConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
