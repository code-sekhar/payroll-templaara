<?php

namespace App\Http\Controllers;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\InviteEmployeeToPortal;
use App\Actions\Employees\UpdateEmployee;
use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Enums\LeaveBalanceAccountStatus;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\User;
use App\Services\Employees\EmployeeLoginAccessPresenter;
use App\Services\Leave\LeaveAmount;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Employee::class);
        $company = $this->activeCompany->current();
        $query = $company->employees()->with(['department:id,name', 'designation:id,name', 'branch:id,name'])->orderBy('last_name')->orderBy('first_name');
        $user = $request->user();
        if (! $user->can('employee.view')) {
            $managerIds = $user->employees()->where('company_id', $company->id)->pluck('id');
            $query->whereIn('manager_id', $managerIds);
        }

        $filters = $request->only(['search', 'department_id', 'designation_id', 'branch_id', 'employment_status', 'employment_type']);
        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $builder) => $builder->where('employee_code', 'like', $search)->orWhere('first_name', 'like', $search)->orWhere('middle_name', 'like', $search)->orWhere('last_name', 'like', $search));
        }
        foreach (['department_id', 'designation_id', 'branch_id', 'employment_status', 'employment_type'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return Inertia::render('employees/index', [
            'employees' => $query->paginate(20)->withQueryString()->through(fn (Employee $employee): array => [
                'id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->full_name,
                'department' => $employee->department?->name, 'designation' => $employee->designation?->name,
                'branch' => $employee->branch?->name, 'employment_status' => $employee->employment_status->value,
                'employment_type' => $employee->employment_type->value,
            ]),
            'filters' => $filters,
            ...$this->organizationOptions(),
            'employmentTypes' => $this->enumOptions(EmploymentType::cases()),
            'employmentStatuses' => $this->enumOptions(EmploymentStatus::cases()),
            'canCreate' => $user->can('employee.create'),
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Employee::class);

        return Inertia::render('employees/form', ['employee' => null, ...$this->formOptions()]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): RedirectResponse
    {
        $employee = $createEmployee->handle($this->activeCompany->current(), $request->validated());

        return to_route('employees.show', $employee);
    }

    public function show(int $employee, EmployeeLoginAccessPresenter $loginAccessPresenter): InertiaResponse
    {
        $record = $this->findEmployee($employee);
        Gate::authorize('view', $record);
        $user = request()->user();
        $canViewHolidayCalendarAssignments = request()->user()->can('holiday_calendar_assignment.view');
        $holidayCalendarAssignment = $canViewHolidayCalendarAssignments ? $record->currentHolidayCalendarAssignment() : null;
        $canViewLeavePolicyAssignments = request()->user()->can('leave_policy_assignment.view')
            && (request()->user()->can('employee.view') || request()->user()->can('employee.view_team'));
        $leavePolicyAssignments = $canViewLeavePolicyAssignments ? $record->currentLeavePolicyAssignments() : collect();
        $activeCompany = $this->activeCompany->current();
        $canViewLeaveBalances = request()->user()->can('leave_balance.view')
            && (request()->user()->can('employee.view') || request()->user()->can('employee.view_team'));
        $canManageLoginAccess = Gate::allows('inviteLogin', $record);
        $loginAccess = $loginAccessPresenter->present($record, $canManageLoginAccess);
        $today = now($activeCompany?->timezone ?: config('app.timezone'))->toDateString();
        $leaveBalanceAccounts = $canViewLeaveBalances
            ? $record->leaveBalanceAccounts()->where('status', LeaveBalanceAccountStatus::Open->value)
                ->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)
                ->with('leaveType:id,name,code')
                ->withSum(['ledgerEntries as balance_total' => fn ($ledger) => $ledger->whereDate('effective_date', '<=', $today)], 'amount')
                ->orderBy('leave_type_id')->get()
            : collect();

        $record->load(['branch:id,name', 'department:id,name', 'designation:id,name', 'manager:id,first_name,last_name', 'user:id,name,email']);

        return Inertia::render('employees/show', [
            'employee' => [
                'id' => $record->id,
                'employee_code' => $record->employee_code,
                'first_name' => $record->first_name,
                'middle_name' => $record->middle_name,
                'last_name' => $record->last_name,
                'date_of_birth' => $record->date_of_birth?->toDateString(),
                'gender' => $record->gender,
                'marital_status' => $record->marital_status,
                'personal_email' => $record->personal_email,
                'personal_phone' => $record->personal_phone,
                'joining_date' => $record->joining_date?->toDateString(),
                'employment_type' => $record->employment_type->value,
                'employment_status' => $record->employment_status->value,
                'address' => $record->address,
                'city' => $record->city,
                'state' => $record->state,
                'country' => $record->country,
                'postal_code' => $record->postal_code,
                'emergency_contact_name' => $record->emergency_contact_name,
                'emergency_contact_phone' => $record->emergency_contact_phone,
                'emergency_contact_relationship' => $record->emergency_contact_relationship,
                'notes' => $record->notes,
                'branch' => $record->branch?->only('id', 'name'),
                'department' => $record->department?->only('id', 'name'),
                'designation' => $record->designation?->only('id', 'name'),
                'manager' => $record->manager?->only('id', 'first_name', 'last_name'),
                'user' => $record->user?->only('name', 'email'),
            ],
            'loginAccess' => $loginAccess,
            'invitationUrl' => $loginAccess['canInvite'] ? route('companies.employees.invitation.store', [
                'company' => $activeCompany,
                'employee' => $record,
            ]) : null,
            'canUpdate' => $user->can('employee.update'),
            'canDelete' => $user->can('employee.delete'),
            'canViewHolidayCalendarAssignments' => $canViewHolidayCalendarAssignments,
            'holidayCalendarAssignment' => $holidayCalendarAssignment ? [
                'name' => $holidayCalendarAssignment->holidayCalendar?->name,
                'year' => $holidayCalendarAssignment->holidayCalendar?->year,
                'effective_from' => $holidayCalendarAssignment->effective_from->toDateString(),
                'effective_to' => $holidayCalendarAssignment->effective_to?->toDateString(),
            ] : null,
            'holidayCalendarAssignmentsUrl' => $activeCompany ? route('companies.employee-holiday-calendar-assignments.index', $activeCompany) : null,
            'canViewLeavePolicyAssignments' => $canViewLeavePolicyAssignments,
            'leavePolicyAssignments' => $leavePolicyAssignments->map(fn ($assignment): array => [
                'leave_type' => $assignment->leaveType?->name ?? 'Archived leave type',
                'policy' => $assignment->leavePolicy?->name ?? 'Archived policy',
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
            ])->values(),
            'leavePolicyAssignmentsUrl' => $activeCompany ? route('companies.leave-policy-assignments.index', $activeCompany) : null,
            'canViewLeaveBalances' => $canViewLeaveBalances,
            'leaveBalanceAccounts' => $leaveBalanceAccounts->map(fn (EmployeeLeaveBalanceAccount $account): array => [
                'id' => $account->id,
                'leave_type' => $account->leaveType?->name ?? 'Archived leave type',
                'period_start' => $account->period_start->toDateString(),
                'period_end' => $account->period_end->toDateString(),
                'balance' => LeaveAmount::fromCents(LeaveAmount::toCents((string) ($account->balance_total ?? '0'))),
            ])->values(),
            'leaveBalancesUrl' => $activeCompany ? route('companies.leave-balances.index', $activeCompany) : null,
        ]);
    }

    public function invite(Company $company, Employee $employee, Request $request, InviteEmployeeToPortal $invite): RedirectResponse
    {
        abort_unless($this->activeCompany->current()?->is($company), 404);
        Gate::authorize('inviteLogin', $employee);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $result = $invite->execute($company, $employee, $actor);
        } catch (EmployeeInvitationException $exception) {
            if ($exception->reason === 'ACTOR_NOT_AUTHORIZED') {
                abort(403);
            }

            return to_route('employees.show', $employee)->with('warning', $this->invitationFailureMessage($exception->reason));
        }

        if (! $result->emailDelivered) {
            return to_route('employees.show', $employee)->with(
                'warning',
                'The invitation was created, but the email could not be sent. Please try sending a new invitation.',
            );
        }

        $message = $result->wasReissued
            ? "A new invitation was sent to {$result->email}. The previous invitation link is no longer valid."
            : "Invitation sent to {$result->email}.";

        return to_route('employees.show', $employee)->with('success', $message);
    }

    public function edit(int $employee): InertiaResponse
    {
        $record = $this->findEmployee($employee);
        Gate::authorize('update', $record);

        return Inertia::render('employees/form', ['employee' => $record, ...$this->formOptions()]);
    }

    public function update(UpdateEmployeeRequest $request, int $employee, UpdateEmployee $updateEmployee): RedirectResponse
    {
        $record = $this->findEmployee($employee);
        Gate::authorize('update', $record);
        $updateEmployee->handle($record, $request->validated());

        return to_route('employees.show', $record);
    }

    public function destroy(int $employee): RedirectResponse
    {
        $record = $this->findEmployee($employee);
        Gate::authorize('delete', $record);
        $record->delete();

        return to_route('employees.index')->with('success', 'Employee archived.');
    }

    private function findEmployee(int $id): Employee
    {
        return $this->activeCompany->current()->employees()->findOrFail($id);
    }

    private function invitationFailureMessage(string $reason): string
    {
        return match ($reason) {
            'EMPLOYEE_ALREADY_HAS_LOGIN' => 'This employee already has login access.',
            'EMPLOYEE_EMAIL_REQUIRED', 'EMPLOYEE_EMAIL_INVALID' => 'Add a valid personal email before sending an invitation.',
            'EMPLOYEE_NOT_ELIGIBLE', 'EMPLOYEE_ARCHIVED' => 'Login invitations are not available for this employee’s current status.',
            default => 'We could not create an invitation. Please check the employee details and try again.',
        };
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $company = $this->activeCompany->current();

        return [
            ...$this->organizationOptions(),
            'managers' => $company->employees()->where('employment_status', '!=', EmploymentStatus::Terminated->value)->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_code']),
            'employmentTypes' => $this->enumOptions(EmploymentType::cases()),
            'employmentStatuses' => $this->enumOptions(EmploymentStatus::cases()),
        ];
    }

    /** @return array<string, mixed> */
    private function organizationOptions(): array
    {
        $company = $this->activeCompany->current();

        return [
            'branches' => $company->branches()->orderBy('name')->get(['id', 'name', 'status']),
            'departments' => $company->departments()->orderBy('name')->get(['id', 'name', 'status']),
            'designations' => $company->designations()->orderBy('name')->get(['id', 'name', 'status']),
        ];
    }

    /** @param array<\BackedEnum> $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => ['value' => $case->value, 'label' => str($case->value)->replace('_', ' ')->title()->toString()], $cases);
    }
}
