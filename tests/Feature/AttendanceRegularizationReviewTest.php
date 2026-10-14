<?php

use App\Actions\AttendanceRegularizations\ApproveAttendanceRegularizationRequest;
use App\Actions\SeedCompanyRoles;
use App\Enums\AttendancePolicyStatus;
use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Enums\AttendanceRegularizationStatus;
use App\Enums\AttendanceRegularizationType;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DayOfWeek;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\Attendance\NoAttendancePolicyAssignmentException;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\AttendancePunchCorrection;
use App\Models\AttendanceRegularizationItem;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Notifications\AttendanceCorrectionApproved;
use App\Services\Attendance\DailyAttendanceCalculator;
use App\Services\Attendance\DailyAttendanceMaterializer;
use App\Services\Attendance\EffectiveAttendancePunch;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function reviewRoleFixture(string $role, string $timezone = 'Asia/Kolkata'): array
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

/** @return array{Company, Employee, WorkSchedule, Shift} */
function reviewScheduleFixture(?Company $company = null, string $weekday = 'monday', string $start = '09:00', string $end = '18:00'): array
{
    $company ??= Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Review Fixture Shift '.fake()->unique()->numerify('###'),
        'code' => 'REV-'.fake()->unique()->numerify('#####'),
        'start_time' => $start,
        'end_time' => $end,
        'break_duration_minutes' => 0,
        'grace_period_minutes' => 0,
        'is_overnight' => Shift::isOvernightTime($start, $end),
        'status' => 'active',
    ]);
    EmployeeWorkScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
        'status' => EmployeeWorkScheduleAssignmentStatus::Active,
    ]);
    $dayOfWeek = match (strtolower($weekday)) {
        'monday' => DayOfWeek::Monday,
        'tuesday' => DayOfWeek::Tuesday,
        'wednesday' => DayOfWeek::Wednesday,
        'thursday' => DayOfWeek::Thursday,
        'friday' => DayOfWeek::Friday,
        'saturday' => DayOfWeek::Saturday,
        'sunday' => DayOfWeek::Sunday,
    };
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', $dayOfWeek->value)
        ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);

    $policy = AttendancePolicy::factory()->create([
        'company_id' => $company->id,
        'status' => AttendancePolicyStatus::Active,
        'half_day_before_minutes' => 0,
        'absent_before_minutes' => 0,
        'overtime_enabled' => false,
    ]);
    EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'status' => EmployeeAttendancePolicyAssignmentStatus::Active,
    ]);

    return [$company, $employee, $schedule, $shift];
}

function reviewPunch(Employee $employee, AttendancePunchType $type, string $localDateTime): AttendancePunch
{
    $timezone = $employee->company->timezone;

    return AttendancePunch::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'punch_type' => $type,
        'punched_at' => CarbonImmutable::parse($localDateTime, $timezone)->utc(),
        'timezone' => $timezone,
    ]);
}

function pendingReviewRequest(Company $company, Employee $employee, User $requester, AttendanceRegularizationType $type, array $items, ?DailyAttendanceRecord $record = null, string $attendanceDate = '2026-10-05'): AttendanceRegularizationRequest
{
    $request = AttendanceRegularizationRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'daily_attendance_record_id' => $record?->id,
        'attendance_date' => $attendanceDate,
        'request_type' => $type,
        'status' => AttendanceRegularizationStatus::Pending,
        'reason' => 'The attendance event requires correction based on the manager record.',
        'employee_note' => null,
        'review_note' => null,
        'requested_by' => $requester->id,
        'requested_at' => CarbonImmutable::parse('2026-10-05 12:00', $company->timezone)->utc(),
        'reviewed_by' => null,
        'reviewed_at' => null,
    ]);

    foreach ($items as $item) {
        AttendanceRegularizationItem::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'attendance_regularization_request_id' => $request->id,
            ...$item,
        ]);
    }

    return $request;
}

function reviewAddItem(AttendancePunchType $type, string $localDateTime): array
{
    return [
        'action' => AttendanceRegularizationItemAction::Add,
        'attendance_punch_id' => null,
        'punch_type' => $type,
        'requested_punched_at' => CarbonImmutable::parse($localDateTime, 'Asia/Kolkata')->utc(),
        'timezone' => 'Asia/Kolkata',
        'notes' => null,
    ];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
});

test('approval creates an effective ADD overlay and recalculates the existing attendance record atomically', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    $employeeUser = User::factory()->create();
    CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);
    $employeeUser->assignRole('employee');
    $registrar->setPermissionsTeamId($previousTeamId);
    $employee->forceFill(['user_id' => $employeeUser->id])->save();
    $checkIn = reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $record = app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));
    $historyBefore = DailyAttendanceRecordHistory::query()->where('daily_attendance_record_id', $record->id)->count();
    $raw = $checkIn->refresh()->getAttributes();
    $requester = User::factory()->create();
    $request = pendingReviewRequest($company, $employee, $requester, AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:03'),
    ], $record);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]), [
        'review_note' => 'Verified against the supervisor register.',
    ])->assertRedirect();

    $record->refresh();
    $rawAfter = AttendancePunch::query()->findOrFail($checkIn->id)->getAttributes();
    ksort($raw);
    ksort($rawAfter);
    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Approved)
        ->and($request->reviewed_by)->toBe($reviewer->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->review_note)->toBe('Verified against the supervisor register.')
        ->and(AttendancePunchCorrection::query()->count())->toBe(1)
        ->and(AttendancePunch::query()->count())->toBe(1)
        ->and($rawAfter)->toBe($raw)
        ->and($record->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($record->worked_minutes)->toBe(543)
        ->and($employeeUser->notifications()->where('type', AttendanceCorrectionApproved::class)->count())->toBe(1)
        ->and(DailyAttendanceRecord::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->whereDate('attendance_date', '2026-10-05')->value('id'))->toBe($record->id)
        ->and(DailyAttendanceRecordHistory::query()->where('daily_attendance_record_id', $record->id)->count())->toBe($historyBefore + 1);

    $this->post(route('companies.attendance-regularizations.approve', [$company, $request]), [
        'review_note' => 'Retry after a lost response.',
    ])->assertSessionHasErrors('request');

    expect(AttendancePunchCorrection::query()->count())->toBe(1)
        ->and($employeeUser->notifications()->where('type', AttendanceCorrectionApproved::class)->count())->toBe(1)
        ->and(DailyAttendanceRecordHistory::query()->where('daily_attendance_record_id', $record->id)->count())->toBe($historyBefore + 1)
        ->and($request->refresh()->review_note)->toBe('Verified against the supervisor register.');
});

test('reject requires a reason and never creates corrections or recalculates attendance', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_manager');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $record = app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));
    $updatedAt = $record->updated_at->toDateTimeString();
    $historyCount = DailyAttendanceRecordHistory::query()->count();
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:00')], $record);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.reject', [$company, $request]), [])->assertSessionHasErrors('review_note');
    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.reject', [$company, $request]), ['review_note' => 'The source register does not support this time.'])->assertRedirect();

    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Rejected)
        ->and($request->review_note)->toBe('The source register does not support this time.')
        ->and(AttendancePunchCorrection::query()->count())->toBe(0)
        ->and($record->refresh()->updated_at->toDateTimeString())->toBe($updatedAt)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount);

    $this->post(route('companies.attendance-regularizations.reject', [$company, $request]), [
        'review_note' => 'A different reason on retry.',
    ])->assertSessionHasErrors('request');

    expect($request->refresh()->review_note)->toBe('The source register does not support this time.')
        ->and(AttendancePunchCorrection::query()->count())->toBe(0)
        ->and($record->refresh()->updated_at->toDateTimeString())->toBe($updatedAt)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount);
});

test('approved REPLACE excludes its original raw event without editing raw evidence', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $originalOut = reviewPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 22:00');
    $rawTime = $originalOut->punched_at->utc()->format('Y-m-d H:i:s');
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::IncorrectPunch, [[
        'action' => AttendanceRegularizationItemAction::Replace,
        'attendance_punch_id' => $originalOut->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'requested_punched_at' => CarbonImmutable::parse('2026-10-05 18:00', $company->timezone)->utc(),
        'timezone' => $company->timezone,
        'notes' => null,
    ]]);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertRedirect();
    $calculation = app(DailyAttendanceCalculator::class)->calculate($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));

    expect($originalOut->refresh()->punched_at->utc()->format('Y-m-d H:i:s'))->toBe($rawTime)
        ->and(AttendancePunch::query()->count())->toBe(2)
        ->and($calculation->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($calculation->workedMinutes)->toBe(540)
        ->and($calculation->punches)->toHaveCount(2)
        ->and($calculation->punches[1])->toBeInstanceOf(EffectiveAttendancePunch::class);
});

test('approved REMOVE suppresses the targeted duplicate but preserves the raw punch', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $duplicate = reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:01');
    reviewPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::DuplicatePunch, [[
        'action' => AttendanceRegularizationItemAction::Remove,
        'attendance_punch_id' => $duplicate->id,
        'punch_type' => null,
        'requested_punched_at' => null,
        'timezone' => null,
        'notes' => 'Duplicate kiosk scan.',
    ]]);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertRedirect();
    $calculation = app(DailyAttendanceCalculator::class)->calculate($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));

    expect(AttendancePunch::query()->count())->toBe(3)
        ->and($duplicate->fresh())->not->toBeNull()
        ->and($calculation->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($calculation->workedMinutes)->toBe(540)
        ->and(AttendancePunchCorrection::query()->sole()->action)->toBe(AttendanceRegularizationItemAction::Remove);
});

test('pending and rejected correction requests have no effect on calculator input', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:00')]);
    $before = app(DailyAttendanceCalculator::class)->calculate($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));

    expect($before->status)->toBe(CalculatedAttendanceStatus::Incomplete);
    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.reject', [$company, $request]), ['review_note' => 'The requested time cannot be verified.'])->assertRedirect();
    $after = app(DailyAttendanceCalculator::class)->calculate($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));

    expect(AttendancePunchCorrection::query()->count())->toBe(0)
        ->and($after->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($after->workedMinutes)->toBe($before->workedMinutes);
});

test('approval is reviewer-independent and manager cannot approve', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    $request = pendingReviewRequest($company, $employee, $reviewer, AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:00')]);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertForbidden();
    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending)
        ->and(AttendancePunchCorrection::query()->count())->toBe(0);

    $manager = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $manager->id, 'status' => 'active']);
    app(SeedCompanyRoles::class)->handle($company);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $manager->assignRole('manager');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($manager)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertForbidden();
});

test('invalid correction data rolls back approval, correction rows, and attendance history', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $record = app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));
    $historyCount = DailyAttendanceRecordHistory::query()->count();
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:00')], $record);
    $item = $request->items()->firstOrFail();
    $item->newQuery()->whereKey($item->id)->update(['requested_punched_at' => CarbonImmutable::parse('2026-10-06 00:01', $company->timezone)->utc()]);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertSessionHasErrors('request');

    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending)
        ->and(AttendancePunchCorrection::query()->count())->toBe(0)
        ->and($record->refresh()->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount);
});

test('review page distinguishes submitted values from approved effective corrections', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:00')]);

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertRedirect();
    $this->actingAs($reviewer)->get(route('companies.attendance-regularizations.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/show', false)
            ->where('request.status', 'approved')
            ->where('request.reviewer', $reviewer->name)
            ->has('request.items.0.approved_correction')
            ->where('request.daily_record.status', 'present'));
});

test('a second approval cannot supersede a raw punch that already has an approved correction', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $originalOut = reviewPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 22:00');
    $first = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::IncorrectPunch, [[
        'action' => AttendanceRegularizationItemAction::Replace,
        'attendance_punch_id' => $originalOut->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'requested_punched_at' => CarbonImmutable::parse('2026-10-05 18:00', $company->timezone)->utc(),
        'timezone' => $company->timezone,
        'notes' => null,
    ]]);
    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $first]))->assertRedirect();
    $second = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::IncorrectPunch, [[
        'action' => AttendanceRegularizationItemAction::Replace,
        'attendance_punch_id' => $originalOut->id,
        'punch_type' => AttendancePunchType::CheckOut,
        'requested_punched_at' => CarbonImmutable::parse('2026-10-05 17:59', $company->timezone)->utc(),
        'timezone' => $company->timezone,
        'notes' => null,
    ]]);
    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $second]))->assertSessionHasErrors('request');

    expect($first->refresh()->status)->toBe(AttendanceRegularizationStatus::Approved)
        ->and($second->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending)
        ->and(AttendancePunchCorrection::query()->count())->toBe(1);
});

test('materialization failure rolls back approval and all correction rows', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $record = app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));
    $historyCount = DailyAttendanceRecordHistory::query()->count();
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:00')], $record);
    EmployeeAttendancePolicyAssignment::query()->where('employee_id', $employee->id)->delete();

    expect(fn () => app(ApproveAttendanceRegularizationRequest::class)
        ->handle($request, $reviewer))->toThrow(NoAttendancePolicyAssignmentException::class);

    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Pending)
        ->and(AttendancePunchCorrection::query()->count())->toBe(0)
        ->and($record->refresh()->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount);
});

test('approved overnight checkout corrections recalculate the shift start date record', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company, 'sunday', '22:00', '06:00');
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-04 22:00');
    $record = app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-04', $company->timezone));
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 06:05')], $record, '2026-10-04');

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))->assertRedirect();

    expect($record->refresh()->attendance_date->toDateString())->toBe('2026-10-04')
        ->and($record->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($record->worked_minutes)->toBe(485)
        ->and(AttendancePunch::query()->count())->toBe(1)
        ->and(AttendancePunchCorrection::query()->sole()->attendance_date->toDateString())->toBe('2026-10-04');
});

test('review detail distinguishes the saved attendance snapshot at request time from current attendance', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00:00', $company->timezone));
    $record = app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:03'),
    ], $record);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 07:00:00', $company->timezone));
    reviewPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:03');
    app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));
    $historyCount = DailyAttendanceRecordHistory::query()->count();
    $recordUpdatedAt = $record->fresh()->updated_at->toDateTimeString();

    $this->actingAs($reviewer)->get(route('companies.attendance-regularizations.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/show', false)
            ->where('request.historical_record.status', 'in_progress')
            ->where('request.daily_record.status', 'present')
            ->where('request.historical_record.captured_at', '05 Oct 2026, 11:00 AM'));

    expect(DailyAttendanceRecordHistory::query()->count())->toBe($historyCount)
        ->and($record->fresh()->updated_at->toDateTimeString())->toBe($recordUpdatedAt);
});

test('review detail reports historical context unavailable rather than inferring it from a later record', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:03'),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00:00', $company->timezone));
    reviewPunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    reviewPunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:03');
    app(DailyAttendanceMaterializer::class)->materialize($employee, CarbonImmutable::parse('2026-10-05', $company->timezone));

    $this->actingAs($reviewer)->get(route('companies.attendance-regularizations.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/show', false)
            ->where('request.historical_record', null)
            ->where('request.daily_record.status', 'present')
            ->where('request.historical_record_missing_reason', 'No materialized attendance record existed when this request was submitted.'));
});

test('stale approval submission returns a refreshed terminal request instead of a stale review action', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:03'),
    ]);
    $request->forceFill([
        'status' => AttendanceRegularizationStatus::Rejected,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => CarbonImmutable::now('UTC'),
        'review_note' => 'Reviewed from another session.',
    ])->save();

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))
        ->assertSessionHasErrors('request');

    $this->get(route('companies.attendance-regularizations.show', [$company, $request]))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/show', false)
            ->where('request.status', 'rejected')
            ->where('request.can_approve', false)
            ->where('request.can_reject', false));

    expect(AttendancePunchCorrection::query()->count())->toBe(0);
});

test('cancelled correction request cannot be approved', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    $request = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:03'),
    ]);
    $request->forceFill([
        'status' => AttendanceRegularizationStatus::Cancelled,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => CarbonImmutable::now('UTC'),
    ])->save();

    $this->actingAs($reviewer)->post(route('companies.attendance-regularizations.approve', [$company, $request]))
        ->assertSessionHasErrors('request');

    expect($request->refresh()->status)->toBe(AttendanceRegularizationStatus::Cancelled)
        ->and(AttendancePunchCorrection::query()->count())->toBe(0);
});

test('management regularization queue defaults reviewers to pending requests but allows an explicit all-status view', function () {
    [$reviewer, $company] = reviewRoleFixture('hr_admin');
    [, $employee] = reviewScheduleFixture($company);
    $pending = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:03'),
    ]);
    $rejected = pendingReviewRequest($company, $employee, User::factory()->create(), AttendanceRegularizationType::MissingCheckOut, [
        reviewAddItem(AttendancePunchType::CheckOut, '2026-10-05 18:04'),
    ], attendanceDate: '2026-10-04');
    $rejected->forceFill([
        'status' => AttendanceRegularizationStatus::Rejected,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => CarbonImmutable::now('UTC'),
        'review_note' => 'Reviewed.',
    ])->save();

    $this->actingAs($reviewer)->get(route('companies.attendance-regularizations.index', $company))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/index', false)
            ->where('filters.status', 'pending')
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $pending->id));

    $this->get(route('companies.attendance-regularizations.index', [$company, 'status' => '']))
        ->assertInertia(fn (Assert $page) => $page->component('attendance-regularizations/index', false)
            ->where('filters.status', '')
            ->has('requests.data', 2));
});
