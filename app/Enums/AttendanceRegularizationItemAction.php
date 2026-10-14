<?php

namespace App\Enums;

enum AttendanceRegularizationItemAction: string
{
    case Add = 'add';
    case Replace = 'replace';
    case Remove = 'remove';
}
