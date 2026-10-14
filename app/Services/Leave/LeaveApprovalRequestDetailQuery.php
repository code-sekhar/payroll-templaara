<?php

namespace App\Services\Leave;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LeaveApprovalRequestDetailQuery
{
    public function findModelForReviewer(
        User $reviewer,
        Company $company,
        int $requestId,
        LeaveRequestReviewerScope $scope,
    ): ?LeaveRequest {
        return $scope->apply(LeaveRequest::query(), $reviewer, $company)
            ->with([
                'employee' => function (BelongsTo $employees): void {
                    $employees->select(['id', 'company_id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'department_id'])
                        ->with('department:id,company_id,name');
                },
                'leaveType' => function (BelongsTo $types): void {
                    $types->withTrashed()->select(['id', 'company_id', 'name']);
                },
                'requester:id,name', 'reviewedBy:id,name', 'canceller:id,name',
                'days' => function (HasMany $days): void {
                    $days->select(['id', 'company_id', 'leave_request_id', 'employee_id', 'leave_date', 'day_type', 'requested_amount'])
                        ->orderBy('leave_date')->orderBy('id');
                },
                'balanceAccount',
            ])
            ->whereKey($requestId)->first();
    }

    /** @return array<string, mixed> */
    public function presentForReviewer(
        LeaveRequest $request,
        User $reviewer,
        Company $company,
        LeaveBalanceCalculator $balances,
        LeaveRequestReservationCalculator $reservations,
    ): array {
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $timeline = [];
        if ($request->requested_at) {
            $timeline[] = ['label' => 'Submitted', 'at' => $request->requested_at->setTimezone($timezone)->format('d M Y, h:i A'), 'actor' => $request->requester?->name];
        }
        if ($request->reviewed_at) {
            $reviewLabel = match ($request->status) {
                LeaveRequestStatus::Approved => 'Approved',
                LeaveRequestStatus::Rejected => 'Rejected',
                LeaveRequestStatus::Cancelled => 'Approved before cancellation',
                default => null,
            };
            if ($reviewLabel !== null) {
                $timeline[] = ['label' => $reviewLabel, 'at' => $request->reviewed_at->setTimezone($timezone)->format('d M Y, h:i A'), 'actor' => $request->reviewedBy?->name];
            }
        }
        if ($request->cancelled_at) {
            $timeline[] = ['label' => 'Cancelled', 'at' => $request->cancelled_at->setTimezone($timezone)->format('d M Y, h:i A'), 'actor' => $request->canceller?->name];
        }

        $balance = null;
        $account = $request->balanceAccount;
        $asOfDate = CarbonImmutable::now($timezone)->toDateString();
        if ($reviewer->can('leave_balance.view') && $account instanceof EmployeeLeaveBalanceAccount
            && (int) $account->company_id === (int) $company->getKey()
            && (int) $account->employee_id === (int) $request->employee_id
            && (int) $account->leave_type_id === (int) $request->leave_type_id
            && (int) $account->leave_policy_id === (int) $request->leave_policy_id
            && (int) $account->employee_leave_policy_assignment_id === (int) $request->employee_leave_policy_assignment_id
            && $account->status === LeaveBalanceAccountStatus::Open
            && $account->period_start->toDateString() <= $asOfDate
            && $account->period_end->toDateString() >= $asOfDate) {
            $calculated = $balances->calculate($account, $asOfDate);
            $reservedCents = $reservations->pendingCents($account);
            $ledgerCents = LeaveAmount::toCents($calculated->balance);
            $balance = [
                'asOfDate' => $asOfDate,
                'periodStart' => $account->period_start->toDateString(),
                'periodEnd' => $account->period_end->toDateString(),
                'ledgerBalance' => $calculated->balance,
                'pendingReserved' => LeaveAmount::fromCents($reservedCents),
                'availableAfterPending' => LeaveAmount::fromCents($ledgerCents - $reservedCents),
                'label' => 'Current balance; this is not the balance at request submission.',
            ];
        }

        $canApprove = $reviewer->can('approve', $request);
        $canReject = $reviewer->can('reject', $request);

        return [
            'request' => [
                'employee' => [
                    'name' => $request->employee?->full_name ?? 'Employee unavailable',
                    'code' => $request->employee?->employee_code,
                    'department' => $request->employee?->department?->name,
                ],
                'leaveType' => $request->leaveType?->name ?? 'Leave type unavailable',
                'status' => $request->status->value,
                'statusLabel' => LeaveApprovalInboxQuery::statusLabel($request->status),
                'startDate' => $request->start_date->toDateString(),
                'endDate' => $request->end_date->toDateString(),
                'requestedDays' => (string) $request->requested_days,
                'duration' => $request->duration === 'half_day' ? 'Half day' : 'Full day',
                'submittedAt' => $request->requested_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'reason' => $request->reason,
                'employeeNote' => $request->employee_note,
                'reviewNote' => $request->review_note,
                'cancellationReason' => $request->cancellation_reason,
                'days' => $request->days->map(fn ($day): array => [
                    'date' => $day->leave_date->toDateString(),
                    'dayType' => self::dayTypeLabel($day->day_type),
                    'amount' => (string) $day->requested_amount,
                ])->values()->all(),
                'timeline' => $timeline,
                'balance' => $balance,
                'decisions' => [
                    'canApprove' => $canApprove,
                    'approveUrl' => $canApprove
                        ? route('companies.leave-requests.approve-from-inbox', [$company, $request])
                        : null,
                    'canReject' => $canReject,
                    'rejectUrl' => $canReject
                        ? route('companies.leave-requests.reject-from-inbox', [$company, $request])
                        : null,
                ],
            ],
            'backUrl' => route('leave.approvals.index'),
        ];
    }

    private static function dayTypeLabel(LeaveRequestDayType $dayType): string
    {
        return match ($dayType) {
            LeaveRequestDayType::FullDay => 'Full day',
            LeaveRequestDayType::HalfDay => 'Half day',
            LeaveRequestDayType::OffDay => 'Scheduled day off',
            LeaveRequestDayType::Holiday => 'Holiday',
        };
    }
}
