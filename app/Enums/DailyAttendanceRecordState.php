<?php

namespace App\Enums;

enum DailyAttendanceRecordState: string
{
    case Provisional = 'provisional';
    case Final = 'final';
    case NeedsReview = 'needs_review';
}
