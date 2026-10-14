<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class LeaveTeamCalendarQuery
{
    private const DAY_DETAIL_PER_PAGE = 20;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function forReviewer(User $reviewer, Company $company, array $filters, LeaveRequestReviewerScope $scope): LeaveTeamCalendarData
    {
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $month = $this->month($filters['month'] ?? null, $today);
        $monthEnd = $month->endOfMonth();
        $selectedDate = $this->selectedDate($filters['date'] ?? null, $month, $monthEnd, $today);
        $normalizedFilters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'leave_type_id' => (string) ($filters['leave_type_id'] ?? ''),
            'status' => $filters['status'] ?? 'all',
            'department_id' => (string) ($filters['department_id'] ?? ''),
        ];

        $requests = $this->filteredRequests($reviewer, $company, $scope, $normalizedFilters);
        $dayCounts = $this->monthlyCounts($requests, $company, $month, $monthEnd);
        $calendarDays = [];
        $approvedEvents = 0;
        $pendingEvents = 0;

        for ($date = $month; $date->lte($monthEnd); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            $counts = $dayCounts[$dateString] ?? ['approved' => 0, 'pending' => 0];
            $calendarDays[] = [
                'date' => $dateString,
                'dayNumber' => $date->day,
                'approvedCount' => $counts['approved'],
                'pendingCount' => $counts['pending'],
            ];
            $approvedEvents += $counts['approved'];
            $pendingEvents += $counts['pending'];
        }

        $selectedDay = $this->selectedDayEvents($requests, $company, $month, $selectedDate, $normalizedFilters);
        $canFilterDepartments = $scope->canViewCompanyRequests($reviewer, $company);

        return new LeaveTeamCalendarData(
            month: $month->format('Y-m'),
            monthLabel: $month->format('F Y'),
            previousMonth: $month->subMonth()->format('Y-m'),
            nextMonth: $month->addMonth()->format('Y-m'),
            today: $today->toDateString(),
            selectedDate: $selectedDate,
            firstWeekdayOffset: $month->dayOfWeekIso - 1,
            timezone: $timezone,
            weekdays: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            days: $calendarDays,
            approvedEvents: $approvedEvents,
            pendingEvents: $pendingEvents,
            selectedDay: $selectedDay,
            filters: $normalizedFilters,
            leaveTypes: LeaveType::withTrashed()->where('company_id', $company->getKey())
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (LeaveType $type): array => ['id' => (int) $type->getKey(), 'name' => $type->name])->all(),
            departments: $canFilterDepartments
                ? Department::query()->where('company_id', $company->getKey())->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Department $department): array => ['id' => (int) $department->getKey(), 'name' => $department->name])->all()
                : [],
            canFilterDepartments: $canFilterDepartments,
            indexUrl: route('leave.calendar.index'),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<LeaveRequest>
     */
    private function filteredRequests(
        User $reviewer,
        Company $company,
        LeaveRequestReviewerScope $scope,
        array $filters,
    ): Builder {
        $query = $scope->apply(
            LeaveRequest::query()->whereIn('status', [LeaveRequestStatus::Approved, LeaveRequestStatus::Pending]),
            $reviewer,
            $company,
        );

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if ($filters['leave_type_id'] !== '') {
            $query->where('leave_type_id', $filters['leave_type_id']);
        }
        if ($filters['department_id'] !== '') {
            $query->whereHas('employee', fn (Builder $employees): Builder => $employees
                ->where('company_id', $company->getKey())
                ->where('department_id', $filters['department_id']));
        }
        if ($filters['search'] !== '') {
            $term = mb_strtolower($filters['search']);
            $query->whereHas('employee', fn (Builder $employees): Builder => $employees->where(function (Builder $employee) use ($term): void {
                $employee->whereRaw('LOWER(employee_code) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(middle_name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$term}%"]);
            }));
        }

        return $query;
    }

    /** @param  Builder<LeaveRequest>  $requests
     * @return array<string, array{approved: int, pending: int}>
     */
    private function monthlyCounts(Builder $requests, Company $company, CarbonImmutable $month, CarbonImmutable $monthEnd): array
    {
        $countQuery = $this->eventQuery($requests, $company);
        if (DB::connection()->getDriverName() === 'sqlite') {
            $countQuery->whereDate('days.leave_date', '>=', $month->toDateString())
                ->whereDate('days.leave_date', '<=', $monthEnd->toDateString());
        } else {
            $countQuery->whereBetween('days.leave_date', [$month->toDateString(), $monthEnd->toDateString()]);
        }

        $counts = $countQuery
            ->selectRaw('DATE(days.leave_date) AS leave_date')
            ->selectRaw("SUM(CASE WHEN requests.status = 'approved' THEN 1 ELSE 0 END) AS approved_count")
            ->selectRaw("SUM(CASE WHEN requests.status = 'pending' THEN 1 ELSE 0 END) AS pending_count")
            ->groupByRaw('DATE(days.leave_date)')
            ->get();

        $mapped = [];
        foreach ($counts as $count) {
            $mapped[$count->leave_date] = [
                'approved' => (int) $count->approved_count,
                'pending' => (int) $count->pending_count,
            ];
        }

        return $mapped;
    }

    /**
     * @param  Builder<LeaveRequest>  $requests
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function selectedDayEvents(
        Builder $requests,
        Company $company,
        CarbonImmutable $month,
        string $date,
        array $filters,
    ): array {
        $query = $this->eventQuery($requests, $company)
            ->join('employees', function (JoinClause $join) use ($company): void {
                $join->on('employees.id', '=', 'days.employee_id')
                    ->on('employees.company_id', '=', 'days.company_id')
                    ->where('employees.company_id', '=', $company->getKey());
            })
            ->leftJoin('leave_types', function (JoinClause $join) use ($company): void {
                $join->on('leave_types.id', '=', 'requests.leave_type_id')
                    ->on('leave_types.company_id', '=', 'requests.company_id')
                    ->where('leave_types.company_id', '=', $company->getKey());
            })
            ->select([
                'days.leave_date', 'days.day_type', 'days.requested_amount', 'days.leave_request_id',
                'employees.employee_code', 'employees.first_name', 'employees.middle_name', 'employees.last_name',
                'leave_types.name as leave_type_name', 'requests.status',
            ])
            ->orderByRaw('LOWER(employees.first_name)')
            ->orderByRaw('LOWER(employees.last_name)')
            ->orderBy('employees.employee_code')
            ->orderBy('days.leave_request_id')
            ->orderBy('days.id');
        if (DB::connection()->getDriverName() === 'sqlite') {
            $query->whereDate('days.leave_date', $date);
        } else {
            $query->where('days.leave_date', $date);
        }

        $queryParams = array_filter([
            'month' => $month->format('Y-m'),
            'date' => $date,
            'search' => $filters['search'] ?: null,
            'leave_type_id' => $filters['leave_type_id'] ?: null,
            'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
            'department_id' => $filters['department_id'] ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        /** @var LengthAwarePaginator $events */
        $events = $query->paginate(self::DAY_DETAIL_PER_PAGE)->appends($queryParams);
        $eventData = $events->getCollection()->map(fn (object $event): array => [
            'employeeName' => $this->employeeName($event),
            'employeeCode' => $event->employee_code,
            'leaveType' => $event->leave_type_name ?: 'Leave type unavailable',
            'status' => $event->status,
            'statusLabel' => $event->status === LeaveRequestStatus::Approved->value ? 'Approved' : 'Pending',
            'amount' => LeaveAmount::fromCents(LeaveAmount::toCents((string) $event->requested_amount)),
            'dayType' => $this->dayTypeLabel((string) $event->day_type),
            'href' => route('leave.approvals.show', $event->leave_request_id),
        ])->values()->all();

        return [
            'date' => $date,
            'data' => $eventData,
            'total' => $events->total(),
            'current_page' => $events->currentPage(),
            'last_page' => $events->lastPage(),
            'per_page' => $events->perPage(),
            'prev_page_url' => $events->previousPageUrl(),
            'next_page_url' => $events->nextPageUrl(),
        ];
    }

    /** @param  Builder<LeaveRequest>  $requests */
    private function eventQuery(Builder $requests, Company $company): \Illuminate\Database\Query\Builder
    {
        return DB::table('leave_request_days as days')
            ->join('leave_requests as requests', function (JoinClause $join) use ($company): void {
                $join->on('requests.id', '=', 'days.leave_request_id')
                    ->on('requests.company_id', '=', 'days.company_id')
                    ->where('requests.company_id', '=', $company->getKey());
            })
            ->where('days.company_id', $company->getKey())
            ->whereIn('requests.id', $requests->select('leave_requests.id'));
    }

    private function month(mixed $requestedMonth, CarbonImmutable $today): CarbonImmutable
    {
        if ($requestedMonth === null) {
            return $today->startOfMonth();
        }

        if (! is_string($requestedMonth) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $requestedMonth)) {
            throw ValidationException::withMessages(['month' => 'Choose a valid calendar month.']);
        }

        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', $requestedMonth, $today->timezone);
        } catch (InvalidArgumentException) {
            $month = null;
        }

        if (! $month || $month->format('Y-m') !== $requestedMonth) {
            throw ValidationException::withMessages(['month' => 'Choose a valid calendar month.']);
        }

        return $month->startOfMonth();
    }

    private function selectedDate(mixed $requestedDate, CarbonImmutable $month, CarbonImmutable $monthEnd, CarbonImmutable $today): string
    {
        if ($requestedDate === null) {
            return $today->betweenIncluded($month, $monthEnd) ? $today->toDateString() : $month->toDateString();
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $requestedDate, $month->timezone);
        } catch (InvalidArgumentException) {
            $date = null;
        }

        if (! $date || $date->format('Y-m-d') !== $requestedDate || $date->lt($month) || $date->gt($monthEnd)) {
            throw ValidationException::withMessages(['date' => 'Choose a date within the displayed month.']);
        }

        return $date->toDateString();
    }

    private function employeeName(object $event): string
    {
        $name = trim(implode(' ', array_filter([
            $event->first_name,
            $event->middle_name,
            $event->last_name,
        ], fn (?string $part): bool => $part !== null && trim($part) !== '')));

        return $name !== '' ? $name : 'Employee unavailable';
    }

    private function dayTypeLabel(string $dayType): string
    {
        return match (LeaveRequestDayType::tryFrom($dayType)) {
            LeaveRequestDayType::FullDay => 'Full day',
            LeaveRequestDayType::HalfDay => 'Half day',
            LeaveRequestDayType::OffDay => 'Scheduled day off (saved snapshot)',
            LeaveRequestDayType::Holiday => 'Holiday (saved snapshot)',
            default => 'Saved leave day',
        };
    }
}
