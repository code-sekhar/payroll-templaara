<?php

namespace App\Http\Controllers;

use App\Actions\LeavePolicyAssignments\CreateEmployeeLeavePolicyAssignment;
use App\Actions\LeavePolicyAssignments\DeleteEmployeeLeavePolicyAssignment;
use App\Actions\LeavePolicyAssignments\UpdateEmployeeLeavePolicyAssignment;
use App\Enums\LeavePolicyStatus;
use App\Http\Requests\LeavePolicyAssignment\IndexEmployeeLeavePolicyAssignmentRequest;
use App\Http\Requests\LeavePolicyAssignment\StoreEmployeeLeavePolicyAssignmentRequest;
use App\Http\Requests\LeavePolicyAssignment\UpdateEmployeeLeavePolicyAssignmentRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeLeavePolicyAssignmentController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(IndexEmployeeLeavePolicyAssignmentRequest $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeLeavePolicyAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $filters = $request->validated();
        $today = now($activeCompany->timezone ?: config('app.timezone'))->toDateString();
        $query = $activeCompany->employeeLeavePolicyAssignments()
            ->with(['employee:id,employee_code,first_name,middle_name,last_name', 'leaveType:id,name,code', 'leavePolicy:id,name,code', 'createdBy:id,name'])
            ->orderBy('employee_id')->orderBy('leave_type_id')->orderByDesc('effective_from')->orderByDesc('id');
        $user = $request->user();

        if (! $user->can('employee.view')) {
            $managerIds = $user->employees()->where('company_id', $activeCompany->id)->select('id');
            $visibleIds = Employee::query()->where('company_id', $activeCompany->id)
                ->where(fn (Builder $builder): Builder => $builder->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds))->select('id');
            $query->whereIn('employee_id', $visibleIds);
        }

        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn (Builder $builder): Builder => $builder
                ->whereHas('employee', fn (Builder $employee): Builder => $employee->where('employee_code', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('middle_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                ->orWhereHas('leaveType', fn (Builder $type): Builder => $type->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))
                ->orWhereHas('leavePolicy', fn (Builder $policy): Builder => $policy->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')));
        }
        foreach (['leave_type_id', 'leave_policy_id', 'status'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (filled($filters['department_id'] ?? null)) {
            $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('department_id', $filters['department_id']));
        }
        if (filled($filters['branch_id'] ?? null)) {
            $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('branch_id', $filters['branch_id']));
        }
        if (filled($filters['date'] ?? null)) {
            $date = $filters['date'];
            $query->whereDate('effective_from', '<=', $date)
                ->where(fn (Builder $builder): Builder => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
        } elseif (($filters['period'] ?? '') === 'future') {
            $query->whereDate('effective_from', '>', $today);
        } elseif (($filters['period'] ?? '') === 'historical') {
            $query->whereNotNull('effective_to')->whereDate('effective_to', '<', $today);
        } elseif (($filters['period'] ?? '') === 'current') {
            $query->whereDate('effective_from', '<=', $today)
                ->where(fn (Builder $builder): Builder => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
        }

        return Inertia::render('leave-policy-assignments/index', [
            'assignments' => $query->paginate(20)->withQueryString()->through(fn (EmployeeLeavePolicyAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee' => ['id' => $assignment->employee_id, 'name' => $assignment->employee?->full_name ?? 'Archived employee', 'code' => $assignment->employee?->employee_code ?? '—'],
                'leave_type' => ['id' => $assignment->leave_type_id, 'name' => $assignment->leaveType?->name ?? 'Archived leave type', 'code' => $assignment->leaveType?->code ?? '—'],
                'leave_policy' => ['id' => $assignment->leave_policy_id, 'name' => $assignment->leavePolicy?->name ?? 'Archived policy', 'code' => $assignment->leavePolicy?->code ?? '—'],
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'status' => $assignment->status->value,
                'period' => $this->periodState($assignment, $today),
                'created_by' => $assignment->createdBy?->name,
            ]),
            'filters' => array_merge(['search' => '', 'leave_type_id' => '', 'leave_policy_id' => '', 'status' => '', 'period' => '', 'date' => '', 'department_id' => '', 'branch_id' => ''], $filters),
            'leaveTypes' => $activeCompany->leaveTypes()->orderBy('name')->get(['id', 'name', 'code']),
            'leavePolicies' => $activeCompany->leavePolicies()->orderBy('name')->get(['id', 'name', 'code', 'leave_type_id']),
            'departments' => $activeCompany->departments()->orderBy('name')->get(['id', 'name']),
            'branches' => $activeCompany->branches()->orderBy('name')->get(['id', 'name']),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $user->can('leave_policy_assignment.create'),
            'canUpdate' => $user->can('leave_policy_assignment.update'),
            'canDelete' => $user->can('leave_policy_assignment.delete'),
        ]);
    }

    public function create(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('create', EmployeeLeavePolicyAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('leave-policy-assignments/form', [
            'assignment' => null,
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany),
        ]);
    }

    public function store(StoreEmployeeLeavePolicyAssignmentRequest $request, Company $company, CreateEmployeeLeavePolicyAssignment $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.leave-policy-assignments.index', $company)->with('success', 'Leave policy assigned to employee.');
    }

    public function edit(Request $request, Company $company, EmployeeLeavePolicyAssignment $employeeLeavePolicyAssignment): InertiaResponse
    {
        Gate::authorize('update', $employeeLeavePolicyAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $employeeLeavePolicyAssignment->load(['employee', 'leaveType', 'leavePolicy']);

        return Inertia::render('leave-policy-assignments/form', [
            'assignment' => [
                'id' => $employeeLeavePolicyAssignment->id,
                'employee_id' => $employeeLeavePolicyAssignment->employee_id,
                'employee_name' => $employeeLeavePolicyAssignment->employee?->full_name ?? 'Archived employee',
                'employee_code' => $employeeLeavePolicyAssignment->employee?->employee_code ?? '—',
                'leave_type_name' => $employeeLeavePolicyAssignment->leaveType?->name ?? 'Archived leave type',
                'leave_policy_id' => $employeeLeavePolicyAssignment->leave_policy_id,
                'leave_policy_name' => $employeeLeavePolicyAssignment->leavePolicy?->name ?? 'Archived policy',
                'effective_from' => $employeeLeavePolicyAssignment->effective_from->toDateString(),
                'effective_to' => $employeeLeavePolicyAssignment->effective_to?->toDateString(),
                'status' => $employeeLeavePolicyAssignment->status->value,
                'notes' => $employeeLeavePolicyAssignment->notes,
            ],
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany, $employeeLeavePolicyAssignment),
        ]);
    }

    public function update(UpdateEmployeeLeavePolicyAssignmentRequest $request, Company $company, EmployeeLeavePolicyAssignment $employeeLeavePolicyAssignment, UpdateEmployeeLeavePolicyAssignment $update): RedirectResponse
    {
        Gate::authorize('update', $employeeLeavePolicyAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $employeeLeavePolicyAssignment, $request->user(), $request->validated());

        return to_route('companies.leave-policy-assignments.index', $company)->with('success', 'Employee leave policy assignment updated.');
    }

    public function destroy(Company $company, EmployeeLeavePolicyAssignment $employeeLeavePolicyAssignment, DeleteEmployeeLeavePolicyAssignment $delete): RedirectResponse
    {
        Gate::authorize('delete', $employeeLeavePolicyAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $delete->handle($activeCompany, $employeeLeavePolicyAssignment, request()->user());

        return to_route('companies.leave-policy-assignments.index', $company)->with('success', 'Employee leave policy assignment archived.');
    }

    /** @return array{employees: Collection, policies: Collection, employeeSearch: string} */
    private function formOptions(Request $request, Company $company, ?EmployeeLeavePolicyAssignment $assignment = null): array
    {
        $employeeSearch = $request->string('employeeSearch')->trim()->toString();
        $employees = $company->employees()->when($employeeSearch !== '', fn (Builder $query): Builder => $query->where(fn (Builder $builder): Builder => $builder
            ->where('employee_code', 'like', '%'.$employeeSearch.'%')->orWhere('first_name', 'like', '%'.$employeeSearch.'%')->orWhere('last_name', 'like', '%'.$employeeSearch.'%')))
            ->orderBy('last_name')->orderBy('first_name')->limit(20)->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
        if ($assignment && ! $employees->contains('id', $assignment->employee_id)) {
            $employees->prepend(Employee::withTrashed()->find($assignment->employee_id));
        }

        $policies = LeavePolicy::withTrashed()->where('company_id', $company->id)
            ->where(fn (Builder $query): Builder => $query->where(fn (Builder $active): Builder => $active
                ->where('status', LeavePolicyStatus::Active->value)->whereNull('deleted_at')
                ->whereHas('leaveType', fn (Builder $type): Builder => $type->where('status', 'active')->whereNull('deleted_at')))
                ->when($assignment, fn (Builder $query): Builder => $query->orWhereKey($assignment->leave_policy_id)))
            ->with('leaveType')->orderBy('name')->get(['id', 'leave_type_id', 'name', 'code', 'status', 'deleted_at']);

        return [
            'employees' => $employees->filter()->map(fn (Employee $employee): array => ['id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->full_name])->values(),
            'policies' => $policies->map(fn (LeavePolicy $policy): array => [
                'id' => $policy->id, 'name' => $policy->name, 'code' => $policy->code,
                'leave_type_id' => $policy->leave_type_id, 'leave_type_name' => $policy->leaveType?->name ?? 'Archived leave type',
                'status' => $policy->status->value, 'deleted' => $policy->trashed(),
            ])->values(),
            'employeeSearch' => $employeeSearch,
        ];
    }

    private function periodState(EmployeeLeavePolicyAssignment $assignment, string $today): string
    {
        if ($assignment->effective_from->toDateString() > $today) {
            return 'future';
        }
        if ($assignment->effective_to !== null && $assignment->effective_to->toDateString() < $today) {
            return 'historical';
        }

        return 'current';
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
