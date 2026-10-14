<?php

namespace App\Http\Controllers;

use App\Actions\EmployeeAttendancePolicyAssignments\CreateEmployeeAttendancePolicyAssignment;
use App\Actions\EmployeeAttendancePolicyAssignments\DeleteEmployeeAttendancePolicyAssignment;
use App\Actions\EmployeeAttendancePolicyAssignments\UpdateEmployeeAttendancePolicyAssignment;
use App\Enums\AttendancePolicyStatus;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Http\Requests\EmployeeAttendancePolicyAssignment\StoreEmployeeAttendancePolicyAssignmentRequest;
use App\Http\Requests\EmployeeAttendancePolicyAssignment\UpdateEmployeeAttendancePolicyAssignmentRequest;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeAttendancePolicyAssignmentController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeAttendancePolicyAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $today = now($activeCompany->timezone ?: config('app.timezone'))->toDateString();
        $query = $activeCompany->employeeAttendancePolicyAssignments()
            ->with(['employee', 'attendancePolicy'])
            ->orderByDesc('effective_from')->orderByDesc('id');
        $user = $request->user();

        if (! $user->can('employee.view')) {
            $teamEmployeeIds = $user->employees()->where('company_id', $activeCompany->id)->select('id');
            $query->whereIn('employee_id', Employee::query()->where('company_id', $activeCompany->id)
                ->where(fn (Builder $builder) => $builder->whereIn('id', $teamEmployeeIds)->orWhereIn('manager_id', $teamEmployeeIds))
                ->select('id'));
        }

        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'attendance_policy_id' => $request->string('attendance_policy_id')->toString(),
            'status' => EmployeeAttendancePolicyAssignmentStatus::tryFrom($request->string('status')->toString())?->value ?? '',
            'effective_date' => $request->string('effective_date')->toString(),
        ];

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $builder) => $builder
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('employee_code', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                ->orWhereHas('attendancePolicy', fn (Builder $policyQuery) => $policyQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')));
        }

        if ($filters['attendance_policy_id'] !== '' && ctype_digit($filters['attendance_policy_id'])) {
            $query->where('attendance_policy_id', $filters['attendance_policy_id']);
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['effective_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['effective_date']) === 1) {
            $date = $filters['effective_date'];
            $query->whereDate('effective_from', '<=', $date)
                ->where(fn (Builder $builder) => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
        }

        $assignments = $query->paginate(20)->withQueryString();

        return Inertia::render('attendance-policy-assignments/index', [
            'assignments' => $assignments->through(fn (EmployeeAttendancePolicyAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee' => [
                    'id' => $assignment->employee_id,
                    'name' => $assignment->employee?->full_name ?? 'Archived employee',
                    'code' => $assignment->employee?->employee_code ?? '—',
                ],
                'policy' => [
                    'id' => $assignment->attendance_policy_id,
                    'name' => $assignment->attendancePolicy?->name ?? 'Archived policy',
                    'code' => $assignment->attendancePolicy?->code ?? '—',
                ],
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'status' => $assignment->status->value,
                'period' => $this->periodState($assignment, $today),
                'updated_at' => $assignment->updated_at?->toDateTimeString(),
            ]),
            'filters' => $filters,
            'policies' => $activeCompany->attendancePolicies()->withTrashed()->orderBy('name')
                ->get(['id', 'name', 'code', 'status', 'deleted_at'])->map(fn (AttendancePolicy $policy): array => [
                    'id' => $policy->id,
                    'name' => $policy->name,
                    'code' => $policy->code,
                    'status' => $policy->status->value,
                    'deleted' => $policy->trashed(),
                ])->values(),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $user->can('attendance_policy_assignment.create'),
            'canUpdate' => $user->can('attendance_policy_assignment.update'),
            'canDelete' => $user->can('attendance_policy_assignment.delete'),
        ]);
    }

    public function create(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('create', EmployeeAttendancePolicyAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('attendance-policy-assignments/form', [
            'assignment' => null,
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany),
        ]);
    }

    public function store(StoreEmployeeAttendancePolicyAssignmentRequest $request, Company $company, CreateEmployeeAttendancePolicyAssignment $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.attendance-policy-assignments.index', $company)->with('success', 'Employee attendance policy assigned.');
    }

    public function edit(Request $request, Company $company, EmployeeAttendancePolicyAssignment $attendancePolicyAssignment): InertiaResponse
    {
        Gate::authorize('update', $attendancePolicyAssignment);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('attendance-policy-assignments/form', [
            'assignment' => [
                'id' => $attendancePolicyAssignment->id,
                'employee_id' => $attendancePolicyAssignment->employee_id,
                'employee_name' => $attendancePolicyAssignment->employee?->full_name ?? 'Archived employee',
                'employee_code' => $attendancePolicyAssignment->employee?->employee_code ?? '—',
                'attendance_policy_id' => $attendancePolicyAssignment->attendance_policy_id,
                'policy_name' => $attendancePolicyAssignment->attendancePolicy?->name ?? 'Archived policy',
                'policy_code' => $attendancePolicyAssignment->attendancePolicy?->code ?? '—',
                'effective_from' => $attendancePolicyAssignment->effective_from->toDateString(),
                'effective_to' => $attendancePolicyAssignment->effective_to?->toDateString(),
                'status' => $attendancePolicyAssignment->status->value,
                'notes' => $attendancePolicyAssignment->notes,
            ],
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany, $attendancePolicyAssignment),
        ]);
    }

    public function update(UpdateEmployeeAttendancePolicyAssignmentRequest $request, Company $company, EmployeeAttendancePolicyAssignment $attendancePolicyAssignment, UpdateEmployeeAttendancePolicyAssignment $update): RedirectResponse
    {
        Gate::authorize('update', $attendancePolicyAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $attendancePolicyAssignment, $request->user(), $request->validated());

        return to_route('companies.attendance-policy-assignments.index', $company)->with('success', 'Employee attendance policy assignment updated.');
    }

    public function destroy(Company $company, EmployeeAttendancePolicyAssignment $attendancePolicyAssignment, DeleteEmployeeAttendancePolicyAssignment $delete): RedirectResponse
    {
        Gate::authorize('delete', $attendancePolicyAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $delete->handle($activeCompany, $attendancePolicyAssignment, request()->user());

        return to_route('companies.attendance-policy-assignments.index', $company)->with('success', 'Employee attendance policy assignment archived.');
    }

    /** @return array{employees: Collection, policies: Collection, employeeSearch: string} */
    private function formOptions(Request $request, Company $company, ?EmployeeAttendancePolicyAssignment $assignment = null): array
    {
        $employeeSearch = $request->string('employeeSearch')->trim()->toString();
        $employees = $company->employees()->when($employeeSearch !== '', fn (Builder $query) => $query->where(fn (Builder $builder) => $builder
            ->where('employee_code', 'like', '%'.$employeeSearch.'%')
            ->orWhere('first_name', 'like', '%'.$employeeSearch.'%')
            ->orWhere('last_name', 'like', '%'.$employeeSearch.'%')))
            ->orderBy('last_name')->orderBy('first_name')->limit(20)
            ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);

        if ($assignment && ! $employees->contains('id', $assignment->employee_id)) {
            $selectedEmployee = $assignment->employee()->first(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
            if ($selectedEmployee) {
                $employees->prepend($selectedEmployee);
            }
        }

        $policies = AttendancePolicy::withTrashed()->where('company_id', $company->id)
            ->where(fn (Builder $query) => $query->where(fn (Builder $eligible) => $eligible
                ->where('status', AttendancePolicyStatus::Active->value)->whereNull('deleted_at'))
                ->when($assignment, fn (Builder $query) => $query->orWhereKey($assignment->attendance_policy_id)))
            ->orderBy('name')->get(['id', 'name', 'code', 'status', 'deleted_at']);

        return [
            'employees' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->full_name,
            ])->values(),
            'policies' => $policies->map(fn (AttendancePolicy $policy): array => [
                'id' => $policy->id,
                'name' => $policy->name,
                'code' => $policy->code,
                'status' => $policy->status->value,
                'deleted' => $policy->trashed(),
            ])->values(),
            'employeeSearch' => $employeeSearch,
        ];
    }

    private function periodState(EmployeeAttendancePolicyAssignment $assignment, string $today): string
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
