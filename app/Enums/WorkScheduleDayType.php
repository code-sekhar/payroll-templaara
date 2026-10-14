<?php

namespace App\Enums;

enum WorkScheduleDayType: string
{
    case Working = 'working';
    case Off = 'off';
    case HalfDay = 'half_day';
}
