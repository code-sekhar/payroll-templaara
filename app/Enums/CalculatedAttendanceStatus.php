<?php

namespace App\Enums;

enum CalculatedAttendanceStatus: string
{
    case Present = 'present';
    case PartialLeave = 'partial_leave';
    case OnLeave = 'on_leave';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case Holiday = 'holiday';
    case OffDay = 'off_day';
    case Incomplete = 'incomplete';
    case NoSchedule = 'no_schedule';
    case InProgress = 'in_progress';
    case NotYetDue = 'not_yet_due';
}
