<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class AttendanceReportQuery
{
    /** @param array<string, mixed> $filters */
    public function forCompany(Company $company, User $user, array $filters): HasMany
    {
        $query = $company->dailyAttendanceRecords()
            ->with(['employee.department', 'employee.designation', 'employee.branch', 'shift', 'workSchedule', 'leaveType'])
            ->whereBetween('attendance_date', [$filters['date_from'], $filters['date_to']]);

        if (! $user->can('employee.view')) {
            $managerEmployeeIds = $user->employees()->where('company_id', $company->id)->select('id');
            $query->whereIn('employee_id', function (QueryBuilder $visibleEmployees) use ($managerEmployeeIds, $company): void {
                $visibleEmployees->from('employees')->select('id')->where('company_id', $company->id)
                    ->where(fn (QueryBuilder $scope): QueryBuilder => $scope->whereIn('id', $managerEmployeeIds)->orWhereIn('manager_id', $managerEmployeeIds));
            });
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('employee_code', 'like', $search)
                ->orWhere('first_name', 'like', $search)
                ->orWhere('middle_name', 'like', $search)
                ->orWhere('last_name', 'like', $search));
        }

        foreach (['department_id', 'designation_id', 'branch_id'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where($filter, $filters[$filter]));
            }
        }

        foreach (['status', 'record_state'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (filled($filters['regularization_status'] ?? null)) {
            $status = $filters['regularization_status'];
            $matches = fn (Builder $requests): Builder => $requests
                ->whereColumn('attendance_regularization_requests.attendance_date', 'daily_attendance_records.attendance_date')
                ->where('attendance_regularization_requests.company_id', $company->id)
                ->when($status !== 'none', fn (Builder $requests): Builder => $requests->where('status', $status));

            $status === 'none'
                ? $query->whereDoesntHave('employee.attendanceRegularizationRequests', $matches)
                : $query->whereHas('employee.attendanceRegularizationRequests', $matches);
        }

        return $query->orderByDesc('attendance_date')->orderBy('employee_id')->orderBy('id');
    }

    /** @return array{total: int, present: int, absent: int, half_day: int, on_leave: int, partial_leave: int, late: int, needs_review: int, pending_regularization: int} */
    public function summary(HasMany $filteredQuery, Company $company): array
    {
        $counts = (clone $filteredQuery)->reorder()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present, SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent, SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) AS half_day, SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) AS on_leave, SUM(CASE WHEN status = 'partial_leave' THEN 1 ELSE 0 END) AS partial_leave, SUM(CASE WHEN is_late THEN 1 ELSE 0 END) AS late, SUM(CASE WHEN record_state = 'needs_review' THEN 1 ELSE 0 END) AS needs_review")->first();
        $pending = (clone $filteredQuery)->reorder()->whereHas('employee.attendanceRegularizationRequests', fn (Builder $requests): Builder => $requests
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->whereColumn('attendance_regularization_requests.attendance_date', 'daily_attendance_records.attendance_date'))->count();

        return [
            'total' => (int) ($counts->total ?? 0),
            'present' => (int) ($counts->present ?? 0),
            'absent' => (int) ($counts->absent ?? 0),
            'half_day' => (int) ($counts->half_day ?? 0),
            'on_leave' => (int) ($counts->on_leave ?? 0),
            'partial_leave' => (int) ($counts->partial_leave ?? 0),
            'late' => (int) ($counts->late ?? 0),
            'needs_review' => (int) ($counts->needs_review ?? 0),
            'pending_regularization' => (int) $pending,
        ];
    }
}
