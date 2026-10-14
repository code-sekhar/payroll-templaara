<?php

namespace App\Services\Employees;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;

final class EmployeeLeaveRequestHistoryQuery
{
    private const PER_PAGE = 15;

    /**
     * @param  array{status?: string|null, leave_type_id?: string|int|null, date?: string|null, page?: string|int|null}  $filters
     */
    public function forEmployee(Employee $employee, Company $company, array $filters): EmployeeLeaveRequestHistoryData
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $query = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->with(['leaveType' => fn ($leaveTypeQuery) => $leaveTypeQuery
                ->select(['id', 'company_id', 'name'])
                ->where('company_id', $company->getKey())]);

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['leave_type_id'] ?? null)) {
            $query->where('leave_type_id', (int) $filters['leave_type_id']);
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('start_date', '<=', $filters['date'])
                ->whereDate('end_date', '>=', $filters['date']);
        }

        $validatedFilters = [
            'status' => filled($filters['status'] ?? null) ? (string) $filters['status'] : null,
            'leave_type_id' => filled($filters['leave_type_id'] ?? null) ? (string) $filters['leave_type_id'] : null,
            'date' => filled($filters['date'] ?? null) ? (string) $filters['date'] : null,
        ];
        $paginator = $query
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'company_id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'requested_days', 'status', 'requested_at'], 'page', (int) ($filters['page'] ?? 1))
            ->appends(array_filter($validatedFilters, static fn (?string $value): bool => $value !== null));

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $requests = $paginator->getCollection()->map(fn (LeaveRequest $request): array => [
            'leaveType' => $request->leaveType?->name ?? 'Leave type unavailable',
            'startDate' => $request->start_date->toDateString(),
            'endDate' => $request->end_date->toDateString(),
            'requestedDays' => (string) $request->requested_days,
            'status' => $request->status->value,
            'statusLabel' => self::statusLabel($request->status),
            'requestedAt' => $request->requested_at?->setTimezone($timezone)->format('d M Y, h:i A') ?? 'Not available',
            'href' => route('employee.leave.requests.show', $request->getKey()),
        ])->values()->all();

        $historicalTypeIds = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->select('leave_type_id')
            ->distinct();

        $leaveTypes = LeaveType::withTrashed()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $historicalTypeIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LeaveType $leaveType): array => ['id' => (int) $leaveType->getKey(), 'name' => $leaveType->name])
            ->values()
            ->all();

        return new EmployeeLeaveRequestHistoryData(
            requests: $requests,
            leaveTypes: $leaveTypes,
            filters: $validatedFilters,
            pagination: [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'previousUrl' => $paginator->previousPageUrl(),
                'nextUrl' => $paginator->nextPageUrl(),
            ],
        );
    }

    public static function statusLabel(LeaveRequestStatus $status): string
    {
        return match ($status) {
            LeaveRequestStatus::Pending => 'Pending',
            LeaveRequestStatus::Approved => 'Approved',
            LeaveRequestStatus::Rejected => 'Rejected',
            LeaveRequestStatus::Cancelled => 'Cancelled',
        };
    }
}
