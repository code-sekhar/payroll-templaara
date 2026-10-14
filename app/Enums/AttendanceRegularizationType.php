<?php

namespace App\Enums;

enum AttendanceRegularizationType: string
{
    case MissingCheckIn = 'missing_check_in';
    case MissingCheckOut = 'missing_check_out';
    case MissingPunchPair = 'missing_punch_pair';
    case IncorrectPunch = 'incorrect_punch';
    case DuplicatePunch = 'duplicate_punch';
    case Other = 'other';
}
