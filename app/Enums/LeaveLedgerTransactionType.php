<?php

namespace App\Enums;

enum LeaveLedgerTransactionType: string
{
    case OpeningBalance = 'opening_balance';
    case Entitlement = 'entitlement';
    case Accrual = 'accrual';
    case CarryForward = 'carry_forward';
    case Adjustment = 'adjustment';
    case LeaveUsed = 'leave_used';
    case LeaveReversed = 'leave_reversed';
    case Expiry = 'expiry';
}
