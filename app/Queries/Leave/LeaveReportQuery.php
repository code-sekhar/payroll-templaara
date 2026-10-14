<?php

namespace App\Queries\Leave;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class LeaveReportQuery
{
    /** @param array<string, mixed> $filters */
    public function paginate(Company $company, array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($company, $filters)
            ->with([
                'employee:id,company_id,employee_code,first_name,middle_name,last_name,department_id,branch_id',
                'leaveType:id,name,code',
                'reviewedBy:id,name',
                'canceller:id,name',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters
     * @return array{total: int, pending: int, approved: int, rejected: int, cancelled: int}
     */
    public function summary(Company $company, array $filters): array
    {
        $counts = $this->buildFilteredQuery($company, $filters, includeStatus: false)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $summary = ['total' => 0];

        foreach (LeaveRequestStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $summary[$status->value] = $count;
            $summary['total'] += $count;
        }

        return $summary;
    }

    /** @return array{leaveTypes: mixed, employees: mixed, departments: mixed, branches: mixed, statuses: list<array{value: string, label: string}>} */
    public function options(Company $company): array
    {
        return [
            'leaveTypes' => $company->leaveTypes()->orderBy('name')->get(['id', 'name']),
            'employees' => $company->employees()->orderBy('employee_code')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']),
            'departments' => $company->departments()->orderBy('name')->get(['id', 'name']),
            'branches' => $company->branches()->orderBy('name')->get(['id', 'name']),
            'statuses' => array_map(fn (LeaveRequestStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], LeaveRequestStatus::cases()),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function filteredQuery(Company $company, array $filters): Builder
    {
        return $this->buildFilteredQuery($company, $filters);
    }

    /** @param array<string, mixed> $filters */
    private function buildFilteredQuery(Company $company, array $filters, bool $includeStatus = true): Builder
    {
        $query = LeaveRequest::query()->where('company_id', $company->getKey());

        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim($filters['search']).'%';
            $query->whereHas('employee', fn (Builder $employee): Builder => $employee
                ->where('employees.company_id', $company->getKey())
                ->where(fn (Builder $name): Builder => $name
                    ->whereRaw('LOWER(employee_code) LIKE LOWER(?)', [$search])
                    ->orWhereRaw('LOWER(first_name) LIKE LOWER(?)', [$search])
                    ->orWhereRaw('LOWER(middle_name) LIKE LOWER(?)', [$search])
                    ->orWhereRaw('LOWER(last_name) LIKE LOWER(?)', [$search])));
        }

        foreach (['leave_type_id', 'employee_id'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        foreach (['department_id', 'branch_id'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->whereHas('employee', fn (Builder $employee): Builder => $employee
                    ->where('employees.company_id', $company->getKey())
                    ->where("employees.{$filter}", $filters[$filter]));
            }
        }

        if ($includeStatus && filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('end_date', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('start_date', '<=', $filters['date_to']);
        }

        return $query;
    }
}
