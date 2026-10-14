<?php

use App\Actions\SeedCompanyRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function leaveReportActor(string $role = 'hr_admin'): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

beforeEach(function (): void {
    config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
});

it('shows the company-scoped report to owner and HR roles only', function (string $role): void {
    [$user, $company] = leaveReportActor($role);

    $this->actingAs($user)->get(route('companies.leave-reports.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('leave-reports/index')->where('summary.total', 0));
})->with(['company_owner', 'hr_admin', 'hr_manager']);

it('denies employees and unauthenticated users and hides another company report', function (): void {
    [$employee, $company] = leaveReportActor('employee');
    [$owner] = leaveReportActor('company_owner');

    $this->actingAs($employee)->get(route('companies.leave-reports.index', $company))->assertForbidden();
    auth()->logout();
    $this->get(route('companies.leave-reports.index', $company))->assertRedirect(route('login'));
    $this->actingAs($owner)->get(route('companies.leave-reports.index', $company))->assertNotFound();
});

it('filters by employee identifiers and leave request period overlap while keeping summary status-independent', function (): void {
    [$hr, $company] = leaveReportActor();
    $matching = LeaveRequest::factory()->create(['company_id' => $company->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-12', 'requested_days' => '2.50']);
    $matching->employee->update(['employee_code' => 'EMP-REPORT-1', 'first_name' => 'Asha', 'last_name' => 'Rao']);
    LeaveRequest::factory()->approved()->create([
        'company_id' => $company->id,
        'employee_leave_policy_assignment_id' => $matching->employee_leave_policy_assignment_id,
        'leave_balance_account_id' => $matching->leave_balance_account_id,
        'start_date' => '2026-10-08',
        'end_date' => '2026-10-11',
    ]);
    LeaveRequest::factory()->rejected()->create(['company_id' => $company->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
    LeaveRequest::factory()->create(['company_id' => $company->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-02']);

    $this->actingAs($hr)->get(route('companies.leave-reports.index', [$company, 'search' => 'EMP-REPORT-1', 'status' => 'pending', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 2)
            ->where('summary.pending', 1)
            ->where('summary.approved', 1)
            ->where('requests.data.0.id', $matching->id)
            ->where('requests.data.0.requested_days', '2.50')
            ->has('options.employees'));

    $this->get(route('companies.leave-reports.index', [$company, 'search' => 'Asha']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 2));
});

it('matches employee searches without regard to case', function (): void {
    [$hr, $company] = leaveReportActor();
    $request = LeaveRequest::factory()->create(['company_id' => $company->id]);
    $request->employee->update(['employee_code' => 'Emp-MiXeD-42', 'first_name' => 'iNdiRa']);

    $this->actingAs($hr)->get(route('companies.leave-reports.index', [$company, 'search' => 'emp-mixed-42']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('requests.data.0.id', $request->id));
});

it('applies company-scoped type employee department and branch filters', function (): void {
    [$hr, $company] = leaveReportActor();
    $department = $company->departments()->create(['name' => 'Operations', 'code' => 'OPS']);
    $branch = $company->branches()->create(['name' => 'Central', 'code' => 'CTR']);
    $request = LeaveRequest::factory()->create(['company_id' => $company->id]);
    $request->employee->update(['department_id' => $department->id, 'branch_id' => $branch->id]);
    $otherCompany = Company::factory()->create();
    $foreignRequest = LeaveRequest::factory()->create(['company_id' => $otherCompany->id]);

    $this->actingAs($hr)->get(route('companies.leave-reports.index', [$company, 'leave_type_id' => $request->leave_type_id, 'employee_id' => $request->employee_id, 'department_id' => $department->id, 'branch_id' => $branch->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('requests.data.0.id', $request->id)
        ->where('requests.total', 1)
        ->where('options.leaveTypes.0.id', $request->leave_type_id)
        ->where('options.employees.0.id', $request->employee_id)
        ->where('options.departments.0.id', $department->id)
        ->where('options.branches.0.id', $branch->id));

    $this->get(route('companies.leave-reports.index', [$company, 'employee_id' => $foreignRequest->employee_id]))->assertSessionHasErrors('employee_id');
    $this->get(route('companies.leave-reports.index', [$company, 'leave_type_id' => $foreignRequest->leave_type_id]))->assertSessionHasErrors('leave_type_id');
    $foreignDepartment = $otherCompany->departments()->create(['name' => 'Foreign department', 'code' => 'FD']);
    $foreignBranch = $otherCompany->branches()->create(['name' => 'Foreign branch', 'code' => 'FB']);
    $this->get(route('companies.leave-reports.index', [$company, 'department_id' => $foreignDepartment->id]))->assertSessionHasErrors('department_id');
    $this->get(route('companies.leave-reports.index', [$company, 'branch_id' => $foreignBranch->id]))->assertSessionHasErrors('branch_id');
});

it('validates date filters and includes saved cancelled and reviewed audit fields', function (): void {
    [$hr, $company] = leaveReportActor();
    $cancelled = LeaveRequest::factory()->cancelled()->create(['company_id' => $company->id]);

    $this->actingAs($hr)->get(route('companies.leave-reports.index', [$company, 'date_from' => '2026-02-30']))->assertSessionHasErrors('date_from');
    $this->get(route('companies.leave-reports.index', [$company, 'date_from' => '2026-11-01', 'date_to' => '2026-10-01']))->assertSessionHasErrors('date_to');
    $this->get(route('companies.leave-reports.index', [$company, 'status' => 'not-a-status']))->assertSessionHasErrors('status');

    $this->get(route('companies.leave-reports.index', [$company, 'status' => 'cancelled']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.total', 1)
        ->where('summary.cancelled', 1)
        ->where('requests.data.0.id', $cancelled->id)
        ->where('requests.data.0.status', 'cancelled')
        ->where('requests.data.0.canceller', $cancelled->canceller->name)
        ->where('requests.data.0.cancellation_reason', 'Plans changed'));
});

it('paginates deterministically and preserves filters without mutating leave records', function (): void {
    [$hr, $company] = leaveReportActor();
    $requests = collect(range(1, 26))->map(fn (int $index): LeaveRequest => LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'requested_at' => now()->subMinutes($index),
    ]));
    $requests->each(fn (LeaveRequest $request) => $request->employee->update(['first_name' => 'Report']));
    $requestCount = LeaveRequest::query()->count();
    $dayCount = LeaveRequestDay::query()->count();
    $ledgerCount = LeaveLedgerEntry::query()->count();
    $accountCount = EmployeeLeaveBalanceAccount::query()->count();
    $attendanceCount = DailyAttendanceRecord::query()->count();

    $this->actingAs($hr)->get(route('companies.leave-reports.index', [$company, 'search' => 'Report', 'page' => 1]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('requests.per_page', 25)
        ->where('requests.total', 26)
        ->where('requests.data.0.id', $requests->first()->id)
        ->where('requests.data.24.id', $requests[24]->id)
        ->where('requests.links.2.url', fn ($url): bool => str_contains((string) $url, 'search=Report')));

    expect(LeaveRequest::query()->count())->toBe($requestCount)
        ->and(LeaveRequestDay::query()->count())->toBe($dayCount)
        ->and(LeaveLedgerEntry::query()->count())->toBe($ledgerCount)
        ->and(EmployeeLeaveBalanceAccount::query()->count())->toBe($accountCount)
        ->and(DailyAttendanceRecord::query()->count())->toBe($attendanceCount);
});
