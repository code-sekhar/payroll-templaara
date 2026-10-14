<?php

namespace App\Http\Controllers;

use App\Actions\EmployeeHolidayCalendarAssignments\CreateEmployeeHolidayCalendarAssignment;
use App\Actions\EmployeeHolidayCalendarAssignments\DeleteEmployeeHolidayCalendarAssignment;
use App\Actions\EmployeeHolidayCalendarAssignments\UpdateEmployeeHolidayCalendarAssignment;
use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Http\Requests\EmployeeHolidayCalendarAssignment\StoreEmployeeHolidayCalendarAssignmentRequest;
use App\Http\Requests\EmployeeHolidayCalendarAssignment\UpdateEmployeeHolidayCalendarAssignmentRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\HolidayCalendar;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeHolidayCalendarAssignmentController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeHolidayCalendarAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->employeeHolidayCalendarAssignments()->with(['employee', 'holidayCalendar', 'creator'])
            ->orderByDesc('effective_from')->orderByDesc('id');
        if (! $request->user()->can('employee.view')) {
            $teamEmployeeIds = $request->user()->employees()->where('company_id', $activeCompany->id)->select('id');
            $query->whereIn('employee_id', Employee::query()->where('company_id', $activeCompany->id)
                ->where(fn (Builder $builder) => $builder->whereIn('id', $teamEmployeeIds)->orWhereIn('manager_id', $teamEmployeeIds))
                ->select('id'));
        }
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'status' => EmployeeHolidayCalendarAssignmentStatus::tryFrom($request->string('status')->toString())?->value ?? '',
            'holiday_calendar_id' => $request->string('holiday_calendar_id')->toString(),
            'effective_date' => $request->string('effective_date')->toString(),
        ];

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $builder) => $builder
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('employee_code', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                ->orWhereHas('holidayCalendar', fn (Builder $calendarQuery) => $calendarQuery->where('name', 'like', '%'.$search.'%')));
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['holiday_calendar_id'] !== '' && ctype_digit($filters['holiday_calendar_id'])) {
            $query->where('holiday_calendar_id', $filters['holiday_calendar_id']);
        }
        if ($filters['effective_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['effective_date']) === 1) {
            $date = $filters['effective_date'];
            $query->whereDate('effective_from', '<=', $date)
                ->where(fn (Builder $builder) => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
        }

        $assignments = $query->paginate(20)->withQueryString();

        return Inertia::render('employee-holiday-calendar-assignments/index', [
            'assignments' => $assignments->through(fn (EmployeeHolidayCalendarAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee' => ['id' => $assignment->employee_id, 'name' => $assignment->employee?->full_name ?? 'Archived employee', 'code' => $assignment->employee?->employee_code ?? '—'],
                'calendar' => ['id' => $assignment->holiday_calendar_id, 'name' => $assignment->holidayCalendar?->name ?? 'Archived calendar', 'year' => $assignment->holidayCalendar?->year],
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'status' => $assignment->status->value,
                'created_by' => $assignment->creator?->name,
            ]),
            'filters' => $filters,
            'calendars' => $activeCompany->holidayCalendars()->orderByDesc('year')->orderBy('name')->get(['id', 'name', 'year'])->values(),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $request->user()->can('holiday_calendar_assignment.create'),
            'canUpdate' => $request->user()->can('holiday_calendar_assignment.update'),
            'canDelete' => $request->user()->can('holiday_calendar_assignment.delete'),
        ]);
    }

    public function create(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('create', EmployeeHolidayCalendarAssignment::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('employee-holiday-calendar-assignments/form', [
            'assignment' => null,
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany),
        ]);
    }

    public function store(StoreEmployeeHolidayCalendarAssignmentRequest $request, Company $company, CreateEmployeeHolidayCalendarAssignment $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.employee-holiday-calendar-assignments.index', $company)->with('success', 'Employee holiday calendar assigned.');
    }

    public function edit(Request $request, Company $company, EmployeeHolidayCalendarAssignment $holidayCalendarAssignment): InertiaResponse
    {
        Gate::authorize('update', $holidayCalendarAssignment);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('employee-holiday-calendar-assignments/form', [
            'assignment' => [
                'id' => $holidayCalendarAssignment->id,
                'employee_id' => $holidayCalendarAssignment->employee_id,
                'employee_name' => $holidayCalendarAssignment->employee?->full_name ?? 'Archived employee',
                'employee_code' => $holidayCalendarAssignment->employee?->employee_code ?? '—',
                'holiday_calendar_id' => $holidayCalendarAssignment->holiday_calendar_id,
                'calendar_name' => $holidayCalendarAssignment->holidayCalendar?->name ?? 'Archived calendar',
                'effective_from' => $holidayCalendarAssignment->effective_from->toDateString(),
                'effective_to' => $holidayCalendarAssignment->effective_to?->toDateString(),
                'status' => $holidayCalendarAssignment->status->value,
                'notes' => $holidayCalendarAssignment->notes,
            ],
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($request, $activeCompany, $holidayCalendarAssignment),
        ]);
    }

    public function update(UpdateEmployeeHolidayCalendarAssignmentRequest $request, Company $company, EmployeeHolidayCalendarAssignment $holidayCalendarAssignment, UpdateEmployeeHolidayCalendarAssignment $update): RedirectResponse
    {
        Gate::authorize('update', $holidayCalendarAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $holidayCalendarAssignment, $request->user(), $request->validated());

        return to_route('companies.employee-holiday-calendar-assignments.index', $company)->with('success', 'Employee holiday calendar assignment updated.');
    }

    public function destroy(Company $company, EmployeeHolidayCalendarAssignment $holidayCalendarAssignment, DeleteEmployeeHolidayCalendarAssignment $delete): RedirectResponse
    {
        Gate::authorize('delete', $holidayCalendarAssignment);
        $activeCompany = $this->assertSelectedCompany($company);
        $delete->handle($activeCompany, $holidayCalendarAssignment, request()->user());

        return to_route('companies.employee-holiday-calendar-assignments.index', $company)->with('success', 'Employee holiday calendar assignment archived.');
    }

    /** @return array{employees: Collection, calendars: Collection, employeeSearch: string} */
    private function formOptions(Request $request, Company $company, ?EmployeeHolidayCalendarAssignment $assignment = null): array
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

        $calendars = HolidayCalendar::withTrashed()->where('company_id', $company->id)
            ->where(fn (Builder $query) => $query->where(fn (Builder $eligible) => $eligible->where('status', HolidayCalendarStatus::Active->value)->whereNull('deleted_at'))
                ->when($assignment, fn (Builder $query) => $query->orWhereKey($assignment->holiday_calendar_id)))
            ->orderByDesc('year')->orderBy('name')->get(['id', 'name', 'year', 'status', 'deleted_at']);

        return [
            'employees' => $employees->filter()->map(fn (Employee $employee): array => ['id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->full_name])->values(),
            'calendars' => $calendars->map(fn (HolidayCalendar $calendar): array => ['id' => $calendar->id, 'name' => $calendar->name, 'year' => $calendar->year, 'status' => $calendar->status->value, 'deleted' => $calendar->trashed()])->values(),
            'employeeSearch' => $employeeSearch,
        ];
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
