<?php

namespace App\Enums;

enum AttendanceDayStatus: string
{
    case WorkingDay = 'working_day';
    case OffDay = 'off_day';
    case Holiday = 'holiday';
    case OnLeave = 'on_leave';
    case NoSchedule = 'no_schedule';
    case InvalidConfiguration = 'invalid_configuration';
}
