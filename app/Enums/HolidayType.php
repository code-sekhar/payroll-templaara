<?php

namespace App\Enums;

enum HolidayType: string
{
    case Public = 'public';
    case Company = 'company';
    case Festival = 'festival';
    case National = 'national';
    case Optional = 'optional';
    case Other = 'other';
}
