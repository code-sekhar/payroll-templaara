<?php

namespace App\Enums;

enum LeaveBalanceAccountStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
