<?php

namespace App\Http\Controllers;

use App\Actions\EmployeeWorkScheduleAssignments\CreateEmployeeWorkScheduleAssignment;
use App\Actions\EmployeeWorkScheduleAssignments\DeleteEmployeeWorkScheduleAssignment;
use App\Actions\EmployeeWorkScheduleAssignments\UpdateEmployeeWorkScheduleAssignment;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Http\Requests\EmployeeWorkScheduleAssignment\StoreEmployeeWorkScheduleAssignmentRequest;
use App\Http\Requests\EmployeeWorkScheduleAssignment\UpdateEmployeeWorkScheduleAssignmentRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\WorkSchedule;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeWorkScheduleAssignmentController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeWorkScheduleAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $today = now($activeCompany->timezone ?: config('app.timezone'))->toDateString();
        $query = $activeCompany->employeeWorkScheduleAssignments()
            ->with(['employee', 'workSchedule', 'creator'])
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
            'employee' => $request->string('employee')->trim()->toString(),
            'schedule_id' => $request->string('schedule_id')->toString(),
            'status' => EmployeeWorkScheduleAssignmentStatus::tryFrom($request->string('status')->toString())?->value ?? '',
            'period' => in_array($request->string('period')->toString(), ['current', 'historical', 'future'], true)
                ? $request->string('period')->toString()
                : '',
        ];

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $builder) => $builder
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('employee_code', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                ->orWhereHas('workSchedule', fn (Builder $scheduleQuery) => $scheduleQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')));
        }
        if ($filters['employee'] !== '') {
            $employeeFilter = $filters['employee'];
            $query->whereHas('employee', fn (Builder $builder) => $builder->where('employee_code', 'like', '%'.$employeeFilter.'%')
                ->orWhere('first_name', 'like', '%'.$employeeFilter.'%')->orWhere('last_name', 'like', '%'.$employeeFilter.'%'));
        }
        if ($filters['schedule_id'] !== '' && ctype_digit($filters['schedule_id'])) {
            $query->where('work_schedule_id', $filters['schedule_id']);
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['period'] === 'future') {
            $query->whereDate('effective_from', '>', $today);
        } elseif ($filters['period'] === 'historical') {
            $query->whereNotNull('effective_to')->whereDate('effective_to', '<', $today);
        } elseif ($filters['period'] === 'current') {
            $query->whereDate('effective_from', '<=', $today)
                ->where(fn (Builder $builder) => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
        }

        $assignments = $query->paginate(20)->withQueryString();

        return Inertia::render('employee-work-schedule-assignments/index', [
            'assignments' => $assignments->through(fn (EmployeeWorkScheduleAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee' => [
                    'id' => $assignment->employee_id,
                    'name' => $assignment->employee?->full_name ?? 'Archived employee',
                    'code' => $assignment->employee?->employee_code ?? '—',
                ],
                'schedule' => [
                    'id' => $assignment->work_schedule_id,
                    'name' => $assignment->workSchedule?->name ?? 'Archived schedule',
                    'code' => $assignment->workSchedule?->code ?? '—',
                ],
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'status' => $assignment->status->value,
                'period' => $this->periodState($assignment, $today),
                'created_by' => $assignment->creator?->name,
            ]),
            'filters' => $filters,
            'schedules' => WorkSchedule::withTrashed()->where('company_id', $activeCompany->id)->orderBy('name')
                ->get(['id', 'name', 'code', 'status', 'deleted_at'])->map(fn (WorkSchedule $schedule): array => [
                    'id' => $schedule->id,
                    'name' => $schedule->name,
                    'code' => $schedule->code,
                    'status' => $schedule->status->value,
                    'deleted' => $schedule->trashed(),
                ])->values(),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $user->can('work_schedule_assignment.create'),
            'canUpdate' => $user->can('work_schedule_assignment.update'),
            'canDelete' => $user->can('work_schedule_assignment.delete'),
        ]);
    }

    public function create(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('create', EmployeeWorkScheduleAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('employee-work-schedule-assignments/create', [
            'assignment' => null,
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany),
        ]);
    }

    public function store(StoreEmployeeWorkScheduleAssignmentRequest $request, Company $company, CreateEmployeeWorkScheduleAssignment $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.work-schedule-assignments.index', $company)->with('success', 'Employee work schedule assigned.');
    }

    public function edit(Request $request, Company $company, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment): InertiaResponse
    {
        Gate::authorize('update', $employeeWorkScheduleAssignment);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('employee-work-schedule-assignments/edit', [
            'assignment' => [
                'id' => $employeeWorkScheduleAssignment->id,
                'employee_id' => $employeeWorkScheduleAssignment->employee_id,
                'employee_name' => $employeeWorkScheduleAssignment->employee?->full_name ?? 'Archived employee',
                'employee_code' => $employeeWorkScheduleAssignment->employee?->employee_code ?? '—',
                'work_schedule_id' => $employeeWorkScheduleAssignment->work_schedule_id,
                'schedule_name' => $employeeWorkScheduleAssignment->workSchedule?->name ?? 'Archived schedule',
                'effective_from' => $employeeWorkScheduleAssignment->effective_from->toDateString(),
                'effective_to' => $employeeWorkScheduleAssignment->effective_to?->toDateString(),
                'status' => $employeeWorkScheduleAssignment->status->value,
                'notes' => $employeeWorkScheduleAssignment->notes,
            ],
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany, $employeeWorkScheduleAssignment),
        ]);
    }

    public function update(UpdateEmployeeWorkScheduleAssignmentRequest $request, Company $company, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment, UpdateEmployeeWorkScheduleAssignment $update): RedirectResponse
    {
        Gate::authorize('update', $employeeWorkScheduleAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $employeeWorkScheduleAssignment, $request->user(), $request->validated());

        return to_route('companies.work-schedule-assignments.index', $company)->with('success', 'Employee work schedule assignment updated.');
    }

    public function destroy(Company $company, EmployeeWorkScheduleAssignment $employeeWorkScheduleAssignment, DeleteEmployeeWorkScheduleAssignment $delete): RedirectResponse
    {
        Gate::authorize('delete', $employeeWorkScheduleAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $delete->handle($activeCompany, $employeeWorkScheduleAssignment, request()->user());

        return to_route('companies.work-schedule-assignments.index', $company)->with('success', 'Employee work schedule assignment archived.');
    }

    /** @return array{employees: Collection, schedules: Collection, employeeSearch: string} */
    private function formOptions(Request $request, Company $company, ?EmployeeWorkScheduleAssignment $assignment = null): array
    {
        $employeeSearch = $request->string('employeeSearch')->trim()->toString();
        $employees = $company->employees()->when($employeeSearch !== '', fn (Builder $query) => $query->where(fn (Builder $builder) => $builder
            ->where('employee_code', 'like', '%'.$employeeSearch.'%')
            ->orWhere('first_name', 'like', '%'.$employeeSearch.'%')
            ->orWhere('last_name', 'like', '%'.$employeeSearch.'%')))
            ->orderBy('last_name')->orderBy('first_name')->limit(20)
            ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);

        if ($assignment && ! $employees->contains('id', $assignment->employee_id)) {
            $employees->prepend($assignment->employee()->first(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']));
        }

        $schedules = WorkSchedule::withTrashed()->where('company_id', $company->id)
            ->where(fn (Builder $query) => $query->where(fn (Builder $active) => $active->where('status', 'active')->whereNull('deleted_at'))
                ->when($assignment, fn (Builder $query) => $query->orWhereKey($assignment->work_schedule_id)))
            ->orderBy('name')->get(['id', 'name', 'code', 'status', 'deleted_at']);

        return [
            'employees' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->full_name,
            ])->values(),
            'schedules' => $schedules->map(fn (WorkSchedule $schedule): array => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'code' => $schedule->code,
                'status' => $schedule->status->value,
                'deleted' => $schedule->trashed(),
            ])->values(),
            'employeeSearch' => $employeeSearch,
        ];
    }

    private function periodState(EmployeeWorkScheduleAssignment $assignment, string $today): string
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
