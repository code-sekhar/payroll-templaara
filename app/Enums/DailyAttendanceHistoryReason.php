<?php

namespace App\Enums;

enum DailyAttendanceHistoryReason: string
{
    case InitialMaterialization = 'initial_materialization';
    case Recalculation = 'recalculation';
}
