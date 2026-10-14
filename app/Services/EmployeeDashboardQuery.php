<?php

namespace App\Services;

use App\Enums\AttendanceDayStatus;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveRequest;
use App\Services\Attendance\AttendanceDayResolver;
use App\Services\Leave\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeDashboardQuery
{
    public function __construct(
        private readonly AttendanceDayResolver $attendanceDayResolver,
        private readonly LeaveBalanceCalculator $leaveBalanceCalculator,
    ) {}

    public function forEmployee(Employee $employee, Company $company): EmployeeDashboardData
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $localNow = CarbonImmutable::now($timezone);
        $today = $localNow->startOfDay();
        $employee = Employee::query()
            ->where('company_id', $company->getKey())
            ->with(['company:id,timezone', 'department:id,name', 'designation:id,name'])
            ->findOrFail($employee->getKey());

        $resolvedDay = $this->attendanceDayResolver->resolve($employee, $today);
        $todayAttendance = DailyAttendanceRecord::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $today->toDateString())
            ->select(['id', 'status', 'first_check_in_at', 'last_check_out_at'])
            ->first();

        $balances = EmployeeLeaveBalanceAccount::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('status', LeaveBalanceAccountStatus::Open->value)
            ->whereDate('period_start', '<=', $today->toDateString())
            ->whereDate('period_end', '>=', $today->toDateString())
            ->whereHas('leaveType', fn (Builder $query): Builder => $query
                ->where('company_id', $company->getKey())
                ->where('status', 'active'))
            ->with(['leaveType:id,company_id,name,sort_order,status'])
            ->orderBy('leave_type_id')
            ->orderBy('id')
            ->limit(4)
            ->get()
            ->map(fn (EmployeeLeaveBalanceAccount $account): array => [
                'leaveType' => $account->leaveType?->name,
                'balance' => $this->leaveBalanceCalculator->calculate($account, $today)->balance,
            ])
            ->filter(fn (array $balance): bool => $balance['leaveType'] !== null)
            ->values()
            ->all();

        $leaveRequests = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->with(['leaveType:id,company_id,name'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (LeaveRequest $request): array => [
                'leaveType' => $request->leaveType && (int) $request->leaveType->company_id === (int) $company->getKey()
                    ? $request->leaveType->name
                    : 'Leave',
                'startDate' => $request->start_date->toDateString(),
                'endDate' => $request->end_date->toDateString(),
                'requestedDays' => (string) $request->requested_days,
                'status' => $request->status->value,
                'requestedAt' => $request->requested_at?->setTimezone($timezone)->format('Y-m-d H:i'),
            ])
            ->all();

        $recentAttendance = DailyAttendanceRecord::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'attendance_date', 'status', 'first_check_in_at', 'last_check_out_at'])
            ->map(fn (DailyAttendanceRecord $record): array => [
                'date' => $record->attendance_date->toDateString(),
                'status' => $this->attendanceStatusLabel($record->status),
                'checkIn' => $this->localTime($record->first_check_in_at, $timezone),
                'checkOut' => $this->localTime($record->last_check_out_at, $timezone),
            ])
            ->all();

        $approvedLeave = $resolvedDay->approvedLeave();
        $workSchedule = $resolvedDay->workSchedule();
        $holiday = $resolvedDay->holiday();

        return new EmployeeDashboardData([
            'employee' => [
                'name' => $employee->full_name,
                'employeeCode' => $employee->employee_code,
                'designation' => $employee->designation?->name,
                'department' => $employee->department?->name,
            ],
            'today' => [
                'date' => $today->toDateString(),
                'dateLabel' => $today->translatedFormat('l, j F Y'),
                'greeting' => $this->greeting($localNow),
                'workStatus' => $this->workStatusLabel($resolvedDay->status()),
                'holidayName' => $holiday->holidayName(),
                'approvedLeaveType' => $approvedLeave->hasApprovedLeave() ? $approvedLeave->leaveType?->name : null,
                'shift' => $workSchedule?->shift ? [
                    'name' => $workSchedule->shift->name,
                    'start' => $workSchedule->shift->start_time,
                    'end' => $workSchedule->shift->end_time,
                ] : null,
                'attendance' => $todayAttendance ? [
                    'status' => $this->attendanceStatusLabel($todayAttendance->status),
                    'checkIn' => $this->localTime($todayAttendance->first_check_in_at, $timezone),
                    'checkOut' => $this->localTime($todayAttendance->last_check_out_at, $timezone),
                ] : null,
            ],
            'leaveBalances' => $balances,
            'pendingLeaveRequestCount' => LeaveRequest::query()
                ->where('company_id', $company->getKey())
                ->where('employee_id', $employee->getKey())
                ->where('status', LeaveRequestStatus::Pending->value)
                ->count(),
            'recentLeaveRequests' => $leaveRequests,
            'recentAttendance' => $recentAttendance,
        ]);
    }

    private function greeting(CarbonImmutable $today): string
    {
        return match (true) {
            $today->hour < 12 => 'Good morning',
            $today->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    private function workStatusLabel(AttendanceDayStatus $status): string
    {
        return match ($status) {
            AttendanceDayStatus::WorkingDay => 'Working day',
            AttendanceDayStatus::OffDay => 'Weekly off',
            AttendanceDayStatus::Holiday => 'Holiday',
            AttendanceDayStatus::OnLeave => 'On approved leave',
            AttendanceDayStatus::NoSchedule => 'No schedule assigned',
            AttendanceDayStatus::InvalidConfiguration => 'Schedule needs attention',
        };
    }

    private function attendanceStatusLabel(?CalculatedAttendanceStatus $status): string
    {
        return match ($status) {
            CalculatedAttendanceStatus::Present => 'Present',
            CalculatedAttendanceStatus::PartialLeave => 'Partial leave',
            CalculatedAttendanceStatus::OnLeave => 'On leave',
            CalculatedAttendanceStatus::HalfDay => 'Half day',
            CalculatedAttendanceStatus::Absent => 'Absent',
            CalculatedAttendanceStatus::Holiday => 'Holiday',
            CalculatedAttendanceStatus::OffDay => 'Weekly off',
            CalculatedAttendanceStatus::Incomplete => 'Incomplete',
            CalculatedAttendanceStatus::NoSchedule => 'No schedule',
            CalculatedAttendanceStatus::InProgress => 'In progress',
            CalculatedAttendanceStatus::NotYetDue => 'Not yet due',
            null => 'Not recorded',
        };
    }

    private function localTime(?CarbonImmutable $dateTime, string $timezone): ?string
    {
        return $dateTime?->setTimezone($timezone)->format('g:i A');
    }
}
