<?php

namespace App\Enums;

enum AttendancePunchType: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
}
