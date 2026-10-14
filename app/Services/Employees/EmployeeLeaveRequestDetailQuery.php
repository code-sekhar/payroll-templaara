<?php

namespace App\Services\Employees;

use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;

final class EmployeeLeaveRequestDetailQuery
{
    public function findForEmployee(Employee $employee, Company $company, int $requestId): ?EmployeeLeaveRequestDetailData
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $request = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereKey($requestId)
            ->with([
                'leaveType' => fn ($query) => $query
                    ->select(['id', 'company_id', 'name'])
                    ->where('company_id', $company->getKey()),
                'days' => fn ($query) => $query
                    ->select(['id', 'company_id', 'leave_request_id', 'employee_id', 'leave_date', 'day_type', 'requested_amount'])
                    ->orderBy('leave_date')
                    ->orderBy('id'),
            ])
            ->first();

        if (! $request) {
            return null;
        }

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $timeline = [];
        if ($request->requested_at) {
            $timeline[] = ['label' => 'Submitted', 'at' => $request->requested_at->setTimezone($timezone)->format('d M Y, h:i A')];
        }

        if ($request->reviewed_at && $request->status === LeaveRequestStatus::Approved) {
            $timeline[] = ['label' => 'Approved', 'at' => $request->reviewed_at->setTimezone($timezone)->format('d M Y, h:i A')];
        } elseif ($request->reviewed_at && $request->status === LeaveRequestStatus::Rejected) {
            $timeline[] = ['label' => 'Rejected', 'at' => $request->reviewed_at->setTimezone($timezone)->format('d M Y, h:i A')];
        } elseif ($request->reviewed_at && $request->status === LeaveRequestStatus::Cancelled && $request->reviewed_by !== null) {
            $timeline[] = ['label' => 'Approved before cancellation', 'at' => $request->reviewed_at->setTimezone($timezone)->format('d M Y, h:i A')];
        }

        if ($request->cancelled_at) {
            $timeline[] = ['label' => 'Cancelled', 'at' => $request->cancelled_at->setTimezone($timezone)->format('d M Y, h:i A')];
        }

        $days = $request->days->map(fn ($day): array => [
            'date' => $day->leave_date->toDateString(),
            'dayType' => self::dayTypeLabel($day->day_type),
            'amount' => (string) $day->requested_amount,
        ])->values()->all();

        $status = $request->status;

        return new EmployeeLeaveRequestDetailData(
            leaveType: $request->leaveType?->name ?? 'Leave type unavailable',
            status: $status->value,
            statusLabel: EmployeeLeaveRequestHistoryQuery::statusLabel($status),
            startDate: $request->start_date->toDateString(),
            endDate: $request->end_date->toDateString(),
            requestedDays: (string) $request->requested_days,
            duration: $request->duration,
            durationLabel: match ($request->duration) {
                'half_day' => 'Half day',
                'full_day' => 'Full day',
                default => 'Request duration unavailable',
            },
            requestedAt: $request->requested_at?->setTimezone($timezone)->format('d M Y, h:i A') ?? 'Not available',
            reason: $request->reason,
            timeline: $timeline,
            hasDayBreakdown: $request->days->isNotEmpty(),
            days: $days,
        );
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
