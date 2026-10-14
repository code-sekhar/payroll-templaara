<?php

namespace App\Enums;

enum AttendancePunchSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Manual = 'manual';
    case Biometric = 'biometric';
    case Api = 'api';
    case Import = 'import';
}
