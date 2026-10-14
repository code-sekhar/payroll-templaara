<?php

namespace App\Enums;

enum LeaveTypeCategory: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Special = 'special';
    case Compensatory = 'compensatory';
}
