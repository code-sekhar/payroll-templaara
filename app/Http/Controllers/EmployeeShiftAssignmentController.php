<?php

namespace App\Http\Controllers;

use App\Actions\EmployeeShiftAssignments\CreateEmployeeShiftAssignment;
use App\Actions\EmployeeShiftAssignments\UpdateEmployeeShiftAssignment;
use App\Enums\EmployeeShiftAssignmentStatus;
use App\Http\Requests\EmployeeShiftAssignment\StoreEmployeeShiftAssignmentRequest;
use App\Http\Requests\EmployeeShiftAssignment\UpdateEmployeeShiftAssignmentRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeShiftAssignmentController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeShiftAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->employeeShiftAssignments()->with(['employee:id,employee_code,first_name,middle_name,last_name', 'shift:id,name,code,start_time,end_time'])
            ->orderByDesc('effective_from')->orderByDesc('id');
        $user = $request->user();

        if (! $user->can('employee.view')) {
            $employeeIds = $user->employees()->where('company_id', $activeCompany->id)->pluck('id');
            $query->whereIn('employee_id', function ($builder) use ($employeeIds, $activeCompany): void {
                $builder->select('id')->from('employees')->where('company_id', $activeCompany->id)
                    ->where(fn ($nested) => $nested->whereIn('id', $employeeIds)->orWhereIn('manager_id', $employeeIds));
            });
        }

        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'shift_id' => $request->string('shift_id')->toString(),
            'status' => EmployeeShiftAssignmentStatus::tryFrom($request->string('status')->toString())?->value ?? '',
        ];
        if ($filters['search'] !== '') {
            $query->whereHas('employee', fn (Builder $builder) => $builder->where('employee_code', 'like', '%'.$filters['search'].'%')
                ->orWhere('first_name', 'like', '%'.$filters['search'].'%')->orWhere('last_name', 'like', '%'.$filters['search'].'%'));
        }
        if ($filters['shift_id'] !== '' && ctype_digit($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        $assignments = $query->paginate(20)->withQueryString();
        $employeeIds = $assignments->getCollection()->pluck('employee_id')->unique();
        $currentAssignments = EmployeeShiftAssignment::query()->where('company_id', $activeCompany->id)
            ->whereIn('employee_id', $employeeIds)->effectiveOn(now($activeCompany->timezone)->toDateString())->with('shift')->get()->keyBy('employee_id');

        return Inertia::render('shift-assignments/index', [
            'assignments' => $assignments->through(fn (EmployeeShiftAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee' => ['id' => $assignment->employee_id, 'name' => $assignment->employee->full_name, 'code' => $assignment->employee->employee_code],
                'shift' => ['id' => $assignment->shift_id, 'name' => $assignment->shift->name, 'start_time' => substr($assignment->shift->start_time, 0, 5), 'end_time' => substr($assignment->shift->end_time, 0, 5)],
                'current_shift' => $currentAssignments->get($assignment->employee_id)?->shift?->name,
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'status' => $assignment->status->value,
            ]),
            'filters' => $filters,
            'shifts' => $activeCompany->shifts()->orderBy('name')->get(['id', 'name', 'status']),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $user->can('shift_assignment.create'),
            'canUpdate' => $user->can('shift_assignment.update'),
            'canDelete' => $user->can('shift_assignment.delete'),
        ]);
    }

    public function create(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('create', EmployeeShiftAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('shift-assignments/form', [
            'assignment' => null,
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany),
        ]);
    }

    public function store(StoreEmployeeShiftAssignmentRequest $request, Company $company, CreateEmployeeShiftAssignment $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.shift-assignments.index', $company)->with('success', 'Shift assignment created.');
    }

    public function edit(Request $request, Company $company, EmployeeShiftAssignment $employeeShiftAssignment): InertiaResponse
    {
        Gate::authorize('update', $employeeShiftAssignment);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('shift-assignments/form', [
            'assignment' => [
                'id' => $employeeShiftAssignment->id,
                'employee_id' => $employeeShiftAssignment->employee_id,
                'shift_id' => $employeeShiftAssignment->shift_id,
                'effective_from' => $employeeShiftAssignment->effective_from->toDateString(),
                'effective_to' => $employeeShiftAssignment->effective_to?->toDateString(),
                'status' => $employeeShiftAssignment->status->value,
                'notes' => $employeeShiftAssignment->notes,
            ],
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany, $employeeShiftAssignment),
        ]);
    }

    public function update(UpdateEmployeeShiftAssignmentRequest $request, Company $company, EmployeeShiftAssignment $employeeShiftAssignment, UpdateEmployeeShiftAssignment $update): RedirectResponse
    {
        Gate::authorize('update', $employeeShiftAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $employeeShiftAssignment, $request->user(), $request->validated());

        return to_route('companies.shift-assignments.index', $company)->with('success', 'Shift assignment updated.');
    }

    public function destroy(Company $company, EmployeeShiftAssignment $employeeShiftAssignment): RedirectResponse
    {
        Gate::authorize('delete', $employeeShiftAssignment);
        $this->assertSelectedCompany($company);
        $employeeShiftAssignment->update(['updated_by' => request()->user()->id]);
        $employeeShiftAssignment->delete();

        return to_route('companies.shift-assignments.index', $company)->with('success', 'Shift assignment archived.');
    }

    /** @return array{employees: Collection, shifts: Collection, employeeSearch: string} */
    private function formOptions(Request $request, Company $company, ?EmployeeShiftAssignment $assignment = null): array
    {
        $search = $request->string('employeeSearch')->trim()->toString();
        $employees = $company->employees()->when($search !== '', function (Builder $query) use ($search): void {
            $query->where(fn (Builder $builder) => $builder->where('employee_code', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'));
        })->orderBy('last_name')->orderBy('first_name')->limit(20)->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);

        if ($assignment && ! $employees->contains('id', $assignment->employee_id)) {
            $employees->prepend($assignment->employee()->first(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']));
        }

        $shifts = $company->shifts()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);
        if ($assignment && ! $shifts->contains('id', $assignment->shift_id)) {
            $shifts->prepend($assignment->shift()->first(['id', 'name', 'code']));
        }

        return [
            'employees' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->full_name,
            ])->values(),
            'shifts' => $shifts->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'name' => $shift->name,
                'code' => $shift->code,
            ])->values(),
            'employeeSearch' => $search,
        ];
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
