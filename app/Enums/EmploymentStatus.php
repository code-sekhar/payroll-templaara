<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Active = 'active';
    case Probation = 'probation';
    case NoticePeriod = 'notice_period';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case Retired = 'retired';
    case Inactive = 'inactive';
}
