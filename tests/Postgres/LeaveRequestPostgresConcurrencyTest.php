<?php

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\RejectLeaveRequest;
use App\Actions\SeedCompanyRoles;
use App\Actions\WorkSchedules\CreateWorkSchedule;
use App\Enums\DayOfWeek;
use App\Enums\LeaveRequestDayType;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\LeaveRequestApproved;
use App\Notifications\LeaveRequestRejected;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') !== '1') {
        $this->markTestSkipped('Set EP4_POSTGRES_TESTING=1 to run destructive PostgreSQL tests against the isolated EP4 test database.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'payroll_ep4_hardening_test') {
        throw new LogicException('PostgreSQL Leave tests may only use the payroll_ep4_hardening_test database.');
    }

    DB::unprepared('DROP SCHEMA public CASCADE');
    DB::unprepared('CREATE SCHEMA public');
    $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
});

afterEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') === '1'
        && config('database.connections.pgsql.database') === 'payroll_ep4_hardening_test') {
        DB::unprepared('DROP SCHEMA public CASCADE');
        DB::unprepared('CREATE SCHEMA public');
    }
});

it('serializes concurrent overlapping Employee Leave submissions on the PostgreSQL Employee lock', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $actor = User::factory()->create();
    $type = LeaveType::factory()->create(['company_id' => $company->getKey(), 'allows_half_day' => false]);
    $policy = LeavePolicy::factory()->create([
        'company_id' => $company->getKey(),
        'leave_type_id' => $type->getKey(),
        'minimum_request_days' => '1.00',
        'minimum_notice_days' => 0,
        'allows_half_day' => false,
    ]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'leave_type_id' => $type->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'status' => 'active',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => 'opening_balance',
        'amount' => '10.00',
        'effective_date' => '2026-01-01',
        'description' => 'PostgreSQL concurrency test opening balance',
    ]);

    $shift = Shift::factory()->create(['company_id' => $company->getKey()]);
    $days = [];
    foreach (DayOfWeek::cases() as $day) {
        $isWorkingDay = $day->value === DayOfWeek::Monday->value;
        $days[] = [
            'day_of_week' => $day->value,
            'day_type' => $isWorkingDay ? WorkScheduleDayType::Working->value : WorkScheduleDayType::Off->value,
            'shift_id' => $isWorkingDay ? $shift->getKey() : null,
        ];
    }
    $schedule = app(CreateWorkSchedule::class)->handle($company, $actor, [
        'name' => 'Leave concurrency schedule',
        'code' => 'LEAVE-CONCURRENCY',
        'status' => 'active',
        'days' => $days,
    ]);
    $company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->getKey(),
        'work_schedule_id' => $schedule->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'status' => 'active',
    ]);

    $results = runEmployeeLeavePostgresRace($company, $employee, $type, $actor, '2026-10-12');

    expect(array_count_values($results))->toMatchArray(['success' => 1, 'validation' => 1])
        ->and(LeaveRequest::query()->where('company_id', $company->getKey())->where('employee_id', $employee->getKey())->where('status', 'pending')->count())->toBe(1)
        ->and(LeaveRequest::query()->where('company_id', $company->getKey())->where('employee_id', $employee->getKey())->count())->toBe(1)
        ->and($account->ledgerEntries()->count())->toBe(1);
});

it('serializes concurrent approved leave cancellation and posts one PostgreSQL reversal', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $actor->assignRole('hr_admin');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $type = LeaveType::factory()->create(['company_id' => $company->getKey()]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->getKey(), 'leave_type_id' => $type->getKey()]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);
    $leaveRequest = LeaveRequest::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'leave_balance_account_id' => $account->getKey(),
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => 'approved',
        'requested_by' => $requester->getKey(),
        'reviewed_by' => $reviewer->getKey(),
        'reviewed_at' => now(),
    ]);
    $leaveRequest->days()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_date' => '2026-10-12',
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
    ]);
    $usage = $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => 'leave_used',
        'amount' => '-1.00',
        'effective_date' => '2026-10-12',
        'description' => 'Approved leave usage for concurrent cancellation',
        'reference_type' => LeaveRequest::class,
        'reference_id' => $leaveRequest->getKey(),
        'created_by' => $reviewer->getKey(),
        'created_at' => now(),
    ]);

    $results = runApprovedLeaveCancellationPostgresRace($leaveRequest, $actor);
    $reversalCount = LeaveLedgerEntry::query()
        ->where('transaction_type', 'leave_reversed')
        ->where('reference_id', $usage->getKey())
        ->count();

    expect(array_count_values($results))->toMatchArray(['cancelled' => 1, 'already_cancelled' => 1])
        ->and($leaveRequest->fresh()->status->value)->toBe('cancelled')
        ->and($reversalCount)->toBe(1)
        ->and(LeaveLedgerEntry::query()->where('reference_type', LeaveRequest::class)->where('reference_id', $leaveRequest->getKey())->count())->toBe(1);
});

it('serializes employee withdrawal against HR approval on PostgreSQL', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $actor->assignRole('hr_admin');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $requester = User::factory()->create();
    $type = LeaveType::factory()->create(['company_id' => $company->getKey()]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->getKey(), 'leave_type_id' => $type->getKey()]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => 'opening_balance',
        'amount' => '5.00',
        'effective_date' => '2026-01-01',
        'description' => 'Opening balance for approval and withdrawal race',
    ]);
    $leaveRequest = LeaveRequest::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'leave_balance_account_id' => $account->getKey(),
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-05',
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => 'pending',
        'requested_by' => $requester->getKey(),
    ]);
    $leaveRequest->days()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_date' => '2026-10-05',
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
    ]);

    $results = runApprovedLeaveCancellationPostgresRace($leaveRequest, $actor, ['pending_cancel', 'approve']);
    $leaveRequest->refresh();
    $usageCount = LeaveLedgerEntry::query()
        ->where('transaction_type', 'leave_used')
        ->where('reference_type', LeaveRequest::class)
        ->where('reference_id', $leaveRequest->getKey())
        ->count();
    $reversalCount = LeaveLedgerEntry::query()
        ->where('transaction_type', 'leave_reversed')
        ->where('reference_id', $leaveRequest->getKey())
        ->count();

    $successfulResult = array_values(array_filter($results, fn (string $result): bool => $result !== 'validation'));

    expect($results)->toHaveCount(2)
        ->and($results)->toContain('validation')
        ->and($successfulResult)->toBeIn([['pending_cancelled'], ['approved']])
        ->and($leaveRequest->status->value)->toBeIn(['approved', 'cancelled'])
        ->and($usageCount)->toBe($leaveRequest->status->value === 'approved' ? 1 : 0)
        ->and($reversalCount)->toBe(0)
        ->and(DailyAttendanceRecord::query()->count())->toBe($leaveRequest->status->value === 'approved' ? 1 : 0);
});

it('serializes concurrent HR approvals and posts exactly one PostgreSQL Leave usage', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create();
    $reviewer = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $reviewer->getKey(), 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->getKey());
    $reviewer->assignRole('hr_admin');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employeeUser = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $employeeUser->getKey(), 'status' => 'active']);
    $registrar->setPermissionsTeamId($company->getKey());
    $employeeUser->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $employeeUser->getKey()]);
    $requester = User::factory()->create();
    $type = LeaveType::factory()->create(['company_id' => $company->getKey()]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->getKey(), 'leave_type_id' => $type->getKey()]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => 'opening_balance',
        'amount' => '5.00',
        'effective_date' => '2026-01-01',
        'description' => 'Opening balance for concurrent approval test',
    ]);
    $leaveRequest = LeaveRequest::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'leave_balance_account_id' => $account->getKey(),
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => 'pending',
        'requested_by' => $requester->getKey(),
    ]);
    $leaveRequest->days()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_date' => '2026-10-12',
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
    ]);

    $results = runApprovedLeaveCancellationPostgresRace($leaveRequest, $reviewer, ['approve', 'approve']);
    $leaveRequest->refresh();
    $usage = LeaveLedgerEntry::query()
        ->where('transaction_type', 'leave_used')
        ->where('reference_type', LeaveRequest::class)
        ->where('reference_id', $leaveRequest->getKey())
        ->get();
    $approvalNotifications = $employeeUser->notifications()
        ->where('company_id', $company->getKey())
        ->where('type', LeaveRequestApproved::class)
        ->get();

    expect(array_count_values($results))->toMatchArray(['approved' => 1, 'validation' => 1])
        ->and($leaveRequest->status->value)->toBe('approved')
        ->and($leaveRequest->reviewed_by)->toBe($reviewer->getKey())
        ->and($usage)->toHaveCount(1)
        ->and($usage->sole()->amount)->toBe('-1.00')
        ->and($usage->sole()->reference_id)->toBe($leaveRequest->getKey())
        ->and($approvalNotifications)->toHaveCount(1)
        ->and((int) $approvalNotifications->sole()->company_id)->toBe((int) $company->getKey())
        ->and($approvalNotifications->sole()->data)->toBe([
            'company_id' => (int) $company->getKey(),
            'leave_request_id' => (int) $leaveRequest->getKey(),
        ])
        ->and(DailyAttendanceRecord::query()->count())->toBe(0);
});

it('serializes PostgreSQL approval against rejection with one terminal state and one lifecycle notification', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    [$company, $reviewer, $employeeUser, $employee, $account, $leaveRequest] = postgresPendingReviewFixture('2026-10-05');
    $results = runApprovedLeaveCancellationPostgresRace($leaveRequest, $reviewer, ['approve', 'reject']);
    $leaveRequest->refresh();
    $usage = LeaveLedgerEntry::query()->where('transaction_type', 'leave_used')
        ->where('reference_type', LeaveRequest::class)->where('reference_id', $leaveRequest->getKey())->get();
    $approvedNotifications = $employeeUser->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestApproved::class)->get();
    $rejectedNotifications = $employeeUser->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestRejected::class)->get();

    expect($results)->toHaveCount(2)
        ->and($results)->toContain('validation')
        ->and(array_values(array_filter($results, fn (string $result): bool => $result !== 'validation')))->toBeIn([['approved'], ['rejected']])
        ->and($leaveRequest->status->value)->toBeIn(['approved', 'rejected'])
        ->and($leaveRequest->reviewed_by)->toBe($reviewer->getKey())
        ->and($usage)->toHaveCount($leaveRequest->status->value === 'approved' ? 1 : 0)
        ->and($usage->first()?->amount)->toBe($leaveRequest->status->value === 'approved' ? '-1.00' : null)
        ->and($approvedNotifications)->toHaveCount($leaveRequest->status->value === 'approved' ? 1 : 0)
        ->and($rejectedNotifications)->toHaveCount($leaveRequest->status->value === 'rejected' ? 1 : 0)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe($leaveRequest->status->value === 'approved' ? 1 : 0)
        ->and(DailyAttendanceRecord::query()->count())->toBe($leaveRequest->status->value === 'approved' ? 1 : 0)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($leaveRequest->status->value === 'approved' ? 1 : 0);
});

it('serializes two PostgreSQL rejection attempts without duplicate ledger or notifications', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    [$company, $reviewer, $employeeUser, $employee, $account, $leaveRequest] = postgresPendingReviewFixture();
    $results = runApprovedLeaveCancellationPostgresRace($leaveRequest, $reviewer, ['reject', 'reject']);

    expect(array_count_values($results))->toMatchArray(['rejected' => 1, 'validation' => 1])
        ->and($leaveRequest->fresh()->status->value)->toBe('rejected')
        ->and($leaveRequest->fresh()->reviewed_by)->toBe($reviewer->getKey())
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->count())->toBe(0)
        ->and($employeeUser->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestRejected::class)->count())->toBe(1);
});

it('rejects stale PostgreSQL approval and rejection commands after the competing terminal outcome', function (): void {
    [$company, $reviewer, , , , $rejectedRequest] = postgresPendingReviewFixture();
    app(RejectLeaveRequest::class)->handle($rejectedRequest, $reviewer, $company, 'Rejected in stale-command test');

    expect(fn () => app(ApproveLeaveRequest::class)->handle($rejectedRequest, $reviewer, $company))
        ->toThrow(ValidationException::class);

    [$companyB, $reviewerB, , , , $approvedRequest] = postgresPendingReviewFixture();
    app(ApproveLeaveRequest::class)->handle($approvedRequest, $reviewerB, $companyB);

    expect(fn () => app(RejectLeaveRequest::class)->handle($approvedRequest, $reviewerB, $companyB, 'Rejected after approval'))
        ->toThrow(ValidationException::class);
});

it('serializes approval against concurrent Attendance materialization on PostgreSQL', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    [$company, $reviewer, $employeeUser, $employee, $account, $request] = postgresPendingReviewFixture('2026-10-05');
    $results = runApprovedAttendancePostgresRace($request, $employee, ['approve', 'materialize'], $reviewer, '2026-10-05');
    $request->refresh();
    $record = DailyAttendanceRecord::query()->where('company_id', $company->getKey())
        ->where('employee_id', $employee->getKey())->whereDate('attendance_date', '2026-10-05')->firstOrFail();

    expect($results)->toHaveCount(2)
        ->and($results)->toContain('approved')
        ->and($results)->toContain('materialized')
        ->and($request->status->value)->toBe('approved')
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->where('reference_id', $request->getKey())->count())->toBe(1)
        ->and($employeeUser->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestApproved::class)->count())->toBe(1)
        ->and(DailyAttendanceRecord::query()->where('company_id', $company->getKey())->where('employee_id', $employee->getKey())->count())->toBe(1)
        ->and($record->leave_request_id)->toBe($request->getKey())
        ->and($record->histories()->count())->toBeIn([1, 2])
        ->and($record->histories()->latest('id')->value('leave_request_id'))->toBe($request->getKey());
});

it('serializes concurrent PostgreSQL Attendance synchronization without duplicate history', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    [$company, $reviewer, $employeeUser, $employee, $account, $request] = postgresPendingReviewFixture('2026-10-05');
    app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company);
    $beforeHistoryCount = DailyAttendanceRecordHistory::query()->count();
    $results = runApprovedAttendancePostgresRace($request, $employee, ['synchronize', 'synchronize']);

    expect($results)->toBe(['synchronized', 'synchronized'])
        ->and(DailyAttendanceRecord::query()->where('company_id', $company->getKey())->where('employee_id', $employee->getKey())->count())->toBe(1)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($beforeHistoryCount)
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->where('reference_id', $request->getKey())->count())->toBe(1)
        ->and($employeeUser->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestApproved::class)->count())->toBe(1);
});

it('rolls back PostgreSQL approval, ledger, Attendance, history, and notification on notification insert failure', function (): void {
    [$company, $reviewer, $employeeUser, $employee, $account, $request] = postgresPendingReviewFixture('2026-10-05');
    DB::unprepared("CREATE FUNCTION reject_leave_approval_notification_for_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'forced notification insert failure'; END; $$");
    DB::unprepared('CREATE TRIGGER reject_leave_approval_notification_for_test BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION reject_leave_approval_notification_for_test()');

    expect(fn () => app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company))
        ->toThrow(QueryException::class);

    expect($request->fresh()->status->value)->toBe('pending')
        ->and($account->ledgerEntries()->where('transaction_type', 'leave_used')->where('reference_id', $request->getKey())->count())->toBe(0)
        ->and(DailyAttendanceRecord::query()->where('employee_id', $employee->getKey())->count())->toBe(0)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe(0)
        ->and($employeeUser->notifications()->where('company_id', $company->getKey())->where('type', LeaveRequestApproved::class)->count())->toBe(0);
});

/** @return list<string> */
function runEmployeeLeavePostgresRace(Company $company, Employee $employee, LeaveType $leaveType, User $actor, string $date): array
{
    $connection = DB::connection();
    $connection->beginTransaction();
    Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

    $workers = [];
    foreach ([1, 2] as $index) {
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'payroll_ep4_hardening_test',
            'EP5_PG_WORKER_APPLICATION_NAME' => 'ep5_leave_'.$index.'_'.uniqid(),
            'EP5_PG_COMPANY_ID' => (string) $company->getKey(),
            'EP5_PG_EMPLOYEE_ID' => (string) $employee->getKey(),
            'EP5_PG_LEAVE_TYPE_ID' => (string) $leaveType->getKey(),
            'EP5_PG_ACTOR_ID' => (string) $actor->getKey(),
            'EP5_PG_LEAVE_DATE' => $date,
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Postgres/employee-leave-request-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($process)) {
            $connection->rollBack();
            throw new RuntimeException('Unable to create a PostgreSQL Leave concurrency worker process.');
        }

        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }

    foreach ($workers as $worker) {
        $ready = trim((string) fgets($worker['pipes'][1]));
        if (! str_starts_with($ready, 'ready:payroll_ep4_hardening_test')) {
            $connection->rollBack();
            throw new RuntimeException('A PostgreSQL Leave worker did not connect to the isolated test database.');
        }
    }

    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "go\n");
        fflush($worker['pipes'][0]);
        fclose($worker['pipes'][0]);
    }

    $deadline = microtime(true) + 8;
    $blockedWorkers = 0;
    do {
        $blockedWorkers = DB::table('pg_stat_activity')
            ->where('datname', 'payroll_ep4_hardening_test')
            ->where('pid', '<>', DB::raw('pg_backend_pid()'))
            ->where('application_name', 'like', 'ep5_leave_%')
            ->where('wait_event_type', 'Lock')
            ->count();

        if ($blockedWorkers === 2) {
            break;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    $connection->commit();
    $results = [];

    foreach ($workers as $worker) {
        $output = stream_get_contents($worker['pipes'][1]);
        $errorOutput = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $lines = array_values(array_filter(explode("\n", trim((string) $output))));
        $resultLine = $lines[array_key_last($lines)] ?? '';
        $result = explode(':', $resultLine, 2)[0];
        $results[] = $exitCode === 0 && in_array($result, ['success', 'validation'], true)
            ? $result
            : 'error:'.($result !== '' ? $result.' '.trim((string) $errorOutput) : trim((string) $errorOutput));
    }

    if ($blockedWorkers !== 2) {
        throw new RuntimeException("Expected two PostgreSQL Leave workers to wait on the Employee lock; observed {$blockedWorkers}. Results: ".implode(', ', $results));
    }

    return $results;
}

/** @return list<string> */
function runApprovedLeaveCancellationPostgresRace(LeaveRequest $leaveRequest, User $actor, array $operations = ['approved_cancel', 'approved_cancel']): array
{
    $connection = DB::connection();
    $connection->beginTransaction();
    LeaveRequest::query()->whereKey($leaveRequest->getKey())->lockForUpdate()->firstOrFail();

    $workers = [];
    foreach ([1, 2] as $index) {
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'payroll_ep4_hardening_test',
            'EP5_CANCEL_PG_WORKER_APPLICATION_NAME' => 'ep5_cancel_'.$index.'_'.uniqid(),
            'EP5_CANCEL_PG_REQUEST_ID' => (string) $leaveRequest->getKey(),
            'EP5_CANCEL_PG_ACTOR_ID' => (string) $actor->getKey(),
            'EP5_CANCEL_PG_OPERATION' => $operations[$index - 1],
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Postgres/approved-leave-cancellation-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($process)) {
            $connection->rollBack();
            throw new RuntimeException('Unable to start a PostgreSQL approved-leave cancellation worker.');
        }

        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }

    foreach ($workers as $worker) {
        $ready = trim((string) fgets($worker['pipes'][1]));
        if (! str_starts_with($ready, 'ready:payroll_ep4_hardening_test')) {
            $connection->rollBack();
            throw new RuntimeException('A PostgreSQL approved-leave cancellation worker did not connect to the isolated database.');
        }
    }

    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "go\n");
        fflush($worker['pipes'][0]);
        fclose($worker['pipes'][0]);
    }

    $deadline = microtime(true) + 8;
    $blockedWorkers = 0;
    do {
        $blockedWorkers = DB::table('pg_stat_activity')
            ->where('datname', 'payroll_ep4_hardening_test')
            ->where('pid', '<>', DB::raw('pg_backend_pid()'))
            ->where('application_name', 'like', 'ep5_cancel_%')
            ->where('wait_event_type', 'Lock')
            ->count();

        if ($blockedWorkers === 2) {
            break;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    $connection->commit();
    $results = [];
    foreach ($workers as $worker) {
        $output = stream_get_contents($worker['pipes'][1]);
        $errorOutput = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $lines = array_values(array_filter(explode("\n", trim((string) $output))));
        $result = explode(':', $lines[array_key_last($lines)] ?? '', 2)[0];
        $results[] = $exitCode === 0 && in_array($result, ['cancelled', 'already_cancelled', 'pending_cancelled', 'approved', 'rejected', 'validation'], true)
            ? $result
            : 'error:'.($result !== '' ? $result.' '.trim((string) $errorOutput) : trim((string) $errorOutput));
    }

    if ($blockedWorkers !== 2) {
        throw new RuntimeException("Expected two approved-cancellation workers to wait on the LeaveRequest lock; observed {$blockedWorkers}. Results: ".implode(', ', $results));
    }

    return $results;
}

/** @return array{Company, User, User, Employee, EmployeeLeaveBalanceAccount, LeaveRequest} */
function postgresPendingReviewFixture(string $date = '2026-10-12'): array
{
    $company = Company::factory()->create();
    $reviewer = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $reviewer->getKey(), 'status' => 'active']);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    app(SeedCompanyRoles::class)->handle($company);
    $registrar->setPermissionsTeamId($company->getKey());
    $reviewer->assignRole('hr_admin');

    $employeeUser = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->getKey(), 'user_id' => $employeeUser->getKey(), 'status' => 'active']);
    $employeeUser->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);

    $employee = Employee::factory()->create(['company_id' => $company->getKey(), 'user_id' => $employeeUser->getKey()]);
    $type = LeaveType::factory()->create(['company_id' => $company->getKey()]);
    $policy = LeavePolicy::factory()->create(['company_id' => $company->getKey(), 'leave_type_id' => $type->getKey()]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'status' => 'open',
    ]);
    $account->ledgerEntries()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'transaction_type' => 'opening_balance',
        'amount' => '5.00',
        'effective_date' => '2026-01-01',
        'description' => 'Opening balance for approval/rejection concurrency test',
    ]);
    $leaveRequest = LeaveRequest::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $type->getKey(),
        'leave_policy_id' => $policy->getKey(),
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
        'leave_balance_account_id' => $account->getKey(),
        'start_date' => $date,
        'end_date' => $date,
        'requested_days' => '1.00',
        'duration' => 'full_day',
        'status' => 'pending',
        'requested_by' => $employeeUser->getKey(),
    ]);
    $leaveRequest->days()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_date' => $date,
        'day_type' => LeaveRequestDayType::FullDay,
        'requested_amount' => '1.00',
    ]);

    return [$company, $reviewer, $employeeUser, $employee, $account, $leaveRequest];
}

/** @param list<string> $operations
 * @return list<string>
 */
function runApprovedAttendancePostgresRace(LeaveRequest $request, Employee $employee, array $operations, ?User $reviewer = null, ?string $date = null): array
{
    $connection = DB::connection();
    $connection->beginTransaction();
    Employee::query()->where('company_id', $employee->company_id)->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

    $workers = [];
    foreach ($operations as $index => $operation) {
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'payroll_ep4_hardening_test',
            'EP5_APPROVED_ATTENDANCE_WORKER_APPLICATION_NAME' => 'ep5_sync_'.($index + 1).'_'.uniqid(),
            'EP5_APPROVED_ATTENDANCE_REQUEST_ID' => (string) $request->getKey(),
            'EP5_APPROVED_ATTENDANCE_REVIEWER_ID' => (string) ($reviewer?->getKey() ?? ''),
            'EP5_APPROVED_ATTENDANCE_OPERATION' => $operation,
            'EP5_APPROVED_ATTENDANCE_DATE' => (string) $date,
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Postgres/approved-leave-attendance-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($process)) {
            $connection->rollBack();
            throw new RuntimeException('Unable to start an approved Leave Attendance worker.');
        }

        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }

    foreach ($workers as $worker) {
        $ready = trim((string) fgets($worker['pipes'][1]));
        if (! str_starts_with($ready, 'ready:payroll_ep4_hardening_test')) {
            $connection->rollBack();
            throw new RuntimeException('An approved Leave Attendance worker did not connect to the isolated PostgreSQL test database.');
        }
    }

    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "go\n");
        fflush($worker['pipes'][0]);
        fclose($worker['pipes'][0]);
    }

    $deadline = microtime(true) + 8;
    $blockedWorkers = 0;
    do {
        $blockedWorkers = DB::table('pg_stat_activity')
            ->where('datname', 'payroll_ep4_hardening_test')
            ->where('pid', '<>', DB::raw('pg_backend_pid()'))
            ->where('application_name', 'like', 'ep5_sync_%')
            ->where('wait_event_type', 'Lock')
            ->count();

        if ($blockedWorkers === count($workers)) {
            break;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    $connection->commit();
    $results = [];

    foreach ($workers as $worker) {
        $output = stream_get_contents($worker['pipes'][1]);
        $errorOutput = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $lines = array_values(array_filter(explode("\n", trim((string) $output))));
        $result = explode(':', $lines[array_key_last($lines)] ?? '', 2)[0];
        $results[] = $exitCode === 0 && in_array($result, ['approved', 'materialized', 'synchronized', 'validation'], true)
            ? $result
            : 'error:'.($result !== '' ? $result.' '.trim((string) $errorOutput) : trim((string) $errorOutput));
    }

    if ($blockedWorkers !== count($workers)) {
        throw new RuntimeException('Expected approved Leave Attendance workers to wait on PostgreSQL row locks; observed '.$blockedWorkers.'. Results: '.implode(', ', $results));
    }

    return $results;
}
