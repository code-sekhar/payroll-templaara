<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeaveApprovalInboxQuery
{
    private const PER_PAGE = 20;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forReviewer(User $reviewer, Company $company, array $filters, LeaveRequestReviewerScope $scope): array
    {
        $query = $scope->apply(LeaveRequest::query(), $reviewer, $company);
        $this->applyFilters($query, $filters, $company);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $requests = $query->with([
            'employee' => function (BelongsTo $employees): void {
                $employees->select(['id', 'company_id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'department_id']);
            },
            'leaveType' => function (BelongsTo $types): void {
                $types->withTrashed()->select(['id', 'company_id', 'name']);
            },
        ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->appends($filters)
            ->through(fn (LeaveRequest $request): array => [
                'employee' => [
                    'name' => $request->employee?->full_name ?? 'Employee unavailable',
                    'code' => $request->employee?->employee_code,
                ],
                'leaveType' => $request->leaveType?->name ?? 'Leave type unavailable',
                'startDate' => $request->start_date->toDateString(),
                'endDate' => $request->end_date->toDateString(),
                'requestedDays' => (string) $request->requested_days,
                'status' => $request->status->value,
                'statusLabel' => self::statusLabel($request->status),
                'requestedAt' => $request->requested_at?->setTimezone($timezone)->format('d M Y, h:i A') ?? 'Not available',
                'href' => route('leave.approvals.show', $request->getKey()),
            ]);

        $visibleTypeIds = $scope->apply(LeaveRequest::query(), $reviewer, $company)->select('leave_type_id')->distinct();
        $leaveTypes = LeaveType::withTrashed()->where('company_id', $company->getKey())
            ->whereIn('id', $visibleTypeIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn (LeaveType $type): array => ['id' => (int) $type->getKey(), 'name' => $type->name])->all();

        $canFilterDepartments = $scope->canViewCompanyRequests($reviewer, $company);
        $departments = $canFilterDepartments
            ? Department::query()->where('company_id', $company->getKey())->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => (int) $department->getKey(), 'name' => $department->name])->all()
            : [];

        return [
            'requests' => $requests,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? LeaveRequestStatus::Pending->value,
                'leave_type_id' => $filters['leave_type_id'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'department_id' => $filters['department_id'] ?? '',
            ],
            'leaveTypes' => $leaveTypes,
            'departments' => $departments,
            'canFilterDepartments' => $canFilterDepartments,
            'indexUrl' => route('leave.approvals.index'),
        ];
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, Company $company): void
    {
        $query->when(($filters['status'] ?? LeaveRequestStatus::Pending->value) !== 'all', fn (Builder $builder): Builder => $builder
            ->where('status', $filters['status'] ?? LeaveRequestStatus::Pending->value))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $builder, string $id): Builder => $builder->where('leave_type_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('start_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('end_date', '<=', $date))
            ->when($filters['department_id'] ?? null, fn (Builder $builder, string $id): Builder => $builder->whereHas('employee', fn (Builder $employees): Builder => $employees
                ->where('company_id', $company->getKey())->where('department_id', $id)))
            ->when($filters['search'] ?? null, function (Builder $builder, string $search): void {
                $term = trim($search);
                $builder->whereHas('employee', fn (Builder $employees): Builder => $employees->where(function (Builder $name) use ($term): void {
                    $name->where('employee_code', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('middle_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%");
                }));
            });
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
