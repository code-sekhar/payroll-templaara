<?php

namespace App\Enums;

enum LeavePeriodClosingBlocker: string
{
    case PeriodNotEnded = 'PERIOD_NOT_ENDED';
    case AccountAlreadyClosed = 'ACCOUNT_ALREADY_CLOSED';
    case AccrualNotFullyReconciled = 'ACCRUAL_NOT_FULLY_RECONCILED';
    case PostedAccrualExceedsCalculatedTarget = 'POSTED_ACCRUAL_EXCEEDS_CALCULATED_TARGET';
    case PendingLeaveReservations = 'PENDING_LEAVE_RESERVATIONS';
    case NegativeClosingBalance = 'NEGATIVE_CLOSING_BALANCE';
    case NextPeriodAccountConflict = 'NEXT_PERIOD_ACCOUNT_CONFLICT';
    case UnsupportedAccrualConfiguration = 'UNSUPPORTED_ACCRUAL_CONFIGURATION';
}
