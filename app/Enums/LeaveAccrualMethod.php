<?php

namespace App\Enums;

enum LeaveAccrualMethod: string
{
    case None = 'none';
    case Upfront = 'upfront';
    case Accrual = 'accrual';
}
