<?php

namespace App\Enums;

enum LeaveRequestDayType: string
{
    case FullDay = 'full_day';
    case HalfDay = 'half_day';
    case OffDay = 'off_day';
    case Holiday = 'holiday';
}
