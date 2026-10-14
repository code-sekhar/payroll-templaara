<?php

use App\Actions\LeaveRequests\CancelApprovedLeaveRequest;
use App\Actions\SeedCompanyRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function leaveReportExportActor(string $role = 'hr_admin', string $timezone = 'Asia/Kolkata'): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['timezone' => $timezone]);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return [$user, $company];
}

/** @return list<list<string|null>> */
function parseLeaveReportCsv(string $content): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, substr($content, 3));
    rewind($stream);
    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

function leaveReportExportUrl(Company $company, array $filters = []): string
{
    return route('companies.leave-reports.export', ['company' => $company, ...$filters]);
}

it('allows company owners and HR roles to stream a company-only CSV download', function (string $role): void {
    [$user, $company] = leaveReportExportActor($role);
    $ownRequest = LeaveRequest::factory()->create(['company_id' => $company->id]);
    $foreignRequest = LeaveRequest::factory()->create();

    $response = $this->actingAs($user)->get(leaveReportExportUrl($company));
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename=leave-report-')
        ->and($response->headers->get('Content-Disposition'))->toContain('.csv');

    $rows = parseLeaveReportCsv($response->streamedContent());
    expect($rows[1][0])->toBe((string) $ownRequest->id)
        ->and(collect($rows)->flatten()->contains((string) $foreignRequest->id))->toBeFalse();
})->with(['company_owner', 'hr_admin', 'hr_manager']);

it('denies unauthenticated and employee exports and hides cross-company report exports', function (): void {
    [$employee, $company] = leaveReportExportActor('employee');
    [$owner, $otherCompany] = leaveReportExportActor('company_owner');

    $this->actingAs($employee)->get(leaveReportExportUrl($company))->assertForbidden();
    auth()->logout();
    $this->get(leaveReportExportUrl($company))->assertRedirect(route('login'));
    $this->actingAs($owner)->get(leaveReportExportUrl($company))->assertNotFound();
    expect($otherCompany->id)->not->toBe($company->id);
});

it('reuses every report filter including status and date overlap without leaking foreign filter IDs', function (): void {
    [$hr, $company] = leaveReportExportActor();
    $department = $company->departments()->create(['name' => 'Operations', 'code' => 'OPS']);
    $branch = $company->branches()->create(['name' => 'Central', 'code' => 'CTR']);
    $match = LeaveRequest::factory()->create(['company_id' => $company->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-12']);
    $match->employee->update(['first_name' => 'Sekhar', 'last_name' => 'Rao', 'department_id' => $department->id, 'branch_id' => $branch->id]);
    $approved = LeaveRequest::factory()->approved()->create([
        'company_id' => $company->id,
        'employee_leave_policy_assignment_id' => $match->employee_leave_policy_assignment_id,
        'leave_balance_account_id' => $match->leave_balance_account_id,
        'start_date' => '2026-10-08',
        'end_date' => '2026-10-11',
    ]);
    $approved->employee->update(['first_name' => 'Sekhar', 'last_name' => 'Rao', 'department_id' => $department->id, 'branch_id' => $branch->id]);
    $foreignRequest = LeaveRequest::factory()->create();
    $foreignDepartment = $foreignRequest->employee->company->departments()->create(['name' => 'Foreign', 'code' => 'FOR']);
    $foreignBranch = $foreignRequest->employee->company->branches()->create(['name' => 'Foreign', 'code' => 'FOR']);

    $filters = [
        'status' => 'approved', 'search' => 'Sekhar', 'leave_type_id' => $match->leave_type_id,
        'employee_id' => $match->employee_id, 'department_id' => $department->id, 'branch_id' => $branch->id,
        'date_from' => '2026-10-10', 'date_to' => '2026-10-10', 'page' => 99,
    ];
    $this->actingAs($hr)->get(route('companies.leave-reports.index', ['company' => $company, ...$filters]))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('exportUrl', route('companies.leave-reports.export', $company))
        ->where('filters.status', 'approved')
        ->where('filters.search', 'Sekhar'));

    $response = $this->actingAs($hr)->get(leaveReportExportUrl($company, $filters));
    $response->assertOk();
    $rows = parseLeaveReportCsv($response->streamedContent());
    expect(count($rows))->toBe(2)
        ->and($rows[1][0])->toBe((string) $approved->id)
        ->and($rows[1][9])->toBe('approved');

    foreach ([
        'employee_id' => $foreignRequest->employee_id,
        'leave_type_id' => $foreignRequest->leave_type_id,
        'department_id' => $foreignDepartment->id,
        'branch_id' => $foreignBranch->id,
    ] as $filter => $value) {
        $this->get(leaveReportExportUrl($company, [$filter => $value]))->assertSessionHasErrors($filter);
    }
});

it('streams every matching row in report order regardless of requested page', function (): void {
    [$hr, $company] = leaveReportExportActor();
    $requests = collect(range(1, 26))->map(fn (int $index): LeaveRequest => LeaveRequest::factory()->create([
        'company_id' => $company->id,
        'requested_at' => CarbonImmutable::parse('2026-10-06 12:00:00')->subMinutes($index),
    ]));
    $requests->each(fn (LeaveRequest $request) => $request->employee->update(['first_name' => 'Exportable']));

    $response = $this->actingAs($hr)->get(leaveReportExportUrl($company, ['search' => 'Exportable', 'page' => 2]));
    $rows = parseLeaveReportCsv($response->streamedContent());

    expect(count($rows))->toBe(27)
        ->and($rows[1][0])->toBe((string) $requests->first()->id)
        ->and($rows[26][0])->toBe((string) $requests->last()->id);
});

it('exports saved amounts and both approval and cancellation audit with safe Unicode CSV cells', function (): void {
    [$hr, $company] = leaveReportExportActor();
    $company->forceFill(['timezone' => 'Asia/Kolkata'])->save();
    $reviewer = User::factory()->create(['name' => 'Reviewer']);
    $request = LeaveRequest::factory()->approved($reviewer)->create([
        'company_id' => $company->id,
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-06',
        'requested_days' => '2.25',
        'requested_at' => '2026-10-04 10:00:00',
        'reviewed_at' => '2026-10-04 12:00:00',
    ]);
    $request->employee->update([
        'employee_code' => '=1+1',
        'first_name' => "+Mira, \"Rao\"\nবাংলা",
        'last_name' => '',
    ]);
    $department = $company->departments()->create(['name' => '-Finance', 'code' => 'FIN']);
    $branch = $company->branches()->create(['name' => '@HQ', 'code' => 'HQ']);
    $request->employee->update(['department_id' => $department->id, 'branch_id' => $branch->id]);
    $request->leaveType->update(['name' => '-Annual Leave']);
    app(CancelApprovedLeaveRequest::class)->execute($request, $hr, '=Cancellation reviewed');
    $request->refresh();

    $requestCount = LeaveRequest::query()->count();
    $dayCount = LeaveRequestDay::query()->count();
    $ledgerCount = LeaveLedgerEntry::query()->count();
    $accountCount = EmployeeLeaveBalanceAccount::query()->count();
    $attendanceCount = DailyAttendanceRecord::query()->count();
    $response = $this->actingAs($hr)->get(leaveReportExportUrl($company, ['status' => 'cancelled']));
    $content = $response->streamedContent();
    $rows = parseLeaveReportCsv($content);
    $row = $rows[1];

    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($rows[0])->toBe([
            'Request ID', 'Employee Code', 'Employee Name', 'Department', 'Branch', 'Leave Type',
            'Start Date', 'End Date', 'Requested Days', 'Status', 'Requested At', 'Reviewed By',
            'Reviewed At', 'Cancelled By', 'Cancelled At', 'Cancellation Reason',
        ])
        ->and($row[1])->toBe("'=1+1")
        ->and($row[2])->toBe("'+Mira, \"Rao\"\nবাংলা")
        ->and($row[3])->toBe("'-Finance")
        ->and($row[4])->toBe("'@HQ")
        ->and($row[5])->toBe("'-Annual Leave")
        ->and($row[6])->toBe('2026-10-05')
        ->and($row[7])->toBe('2026-10-06')
        ->and($row[8])->toBe('2.25')
        ->and($row[9])->toBe('cancelled')
        ->and($row[10])->toContain('+05:30')
        ->and($row[11])->toBe('Reviewer')
        ->and($row[12])->toContain('+05:30')
        ->and($row[13])->toBe($hr->name)
        ->and($row[15])->toBe("'=Cancellation reviewed")
        ->and(LeaveRequest::query()->count())->toBe($requestCount)
        ->and(LeaveRequestDay::query()->count())->toBe($dayCount)
        ->and(LeaveLedgerEntry::query()->count())->toBe($ledgerCount)
        ->and(EmployeeLeaveBalanceAccount::query()->count())->toBe($accountCount)
        ->and(DailyAttendanceRecord::query()->count())->toBe($attendanceCount);
});

it('protects formula cells preceded by Unicode control characters', function (): void {
    [$hr, $company] = leaveReportExportActor();
    $request = LeaveRequest::factory()->create(['company_id' => $company->id]);
    $request->employee->update(['first_name' => "\u{0085}=1+1", 'last_name' => '']);

    $rows = parseLeaveReportCsv($this->actingAs($hr)->get(leaveReportExportUrl($company))->streamedContent());

    expect($rows[1][2])->toBe("'\u{0085}=1+1");
});

it('returns only the CSV header for an empty filtered export and uses a safe range filename', function (): void {
    [$hr, $company] = leaveReportExportActor();
    $response = $this->actingAs($hr)->get(leaveReportExportUrl($company, [
        'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'search' => 'no match', 'page' => 5,
    ]));

    expect($response->headers->get('Content-Disposition'))->toContain('leave-report-2026-10-01-to-2026-10-31.csv')
        ->and(parseLeaveReportCsv($response->streamedContent()))->toHaveCount(1);
});
