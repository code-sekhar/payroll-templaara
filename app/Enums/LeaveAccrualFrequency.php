<?php

namespace App\Enums;

enum LeaveAccrualFrequency: string
{
    case None = 'none';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
}
