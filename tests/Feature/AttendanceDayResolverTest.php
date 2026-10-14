<?php

use App\Enums\AttendanceDayStatus;
use App\Enums\DayOfWeek;
use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\Holidays\InvalidHolidayCalendarConfigurationException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\AttendanceDayResolver;
use App\Services\Attendance\ResolvedAttendanceDay;
use App\Services\Holidays\HolidayResolver;
use App\Services\Leave\ApprovedLeaveResolver;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;

/** @return array{Company, Employee, WorkSchedule, Shift} */
function attendanceDayFixture(string $timezone = 'UTC', bool $mondayIsWorking = true): array
{
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'General Shift',
        'code' => 'DAY-'.fake()->unique()->numerify('####'),
        'start_time' => '09:00',
        'end_time' => '18:00',
        'break_duration_minutes' => 60,
        'grace_period_minutes' => 10,
        'is_overnight' => false,
        'status' => 'active',
    ]);

    if ($mondayIsWorking) {
        WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
            ->where('day_of_week', DayOfWeek::Monday->value)
            ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => $shift->id]);
    }

    return [$company, $employee, $schedule, $shift];
}

function attendanceDayAssignSchedule(Employee $employee, WorkSchedule $schedule): EmployeeWorkScheduleAssignment
{
    return $employee->company->employeeWorkScheduleAssignments()->create([
        'employee_id' => $employee->id,
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
        'status' => EmployeeWorkScheduleAssignmentStatus::Active,
    ]);
}

function attendanceDayCalendar(Company $company, array $attributes = []): HolidayCalendar
{
    return HolidayCalendar::factory()->create(array_merge([
        'company_id' => $company->id,
        'year' => 2026,
        'status' => HolidayCalendarStatus::Active,
    ], $attributes));
}

function attendanceDayAssignCalendar(Employee $employee, HolidayCalendar $calendar): EmployeeHolidayCalendarAssignment
{
    return $employee->company->employeeHolidayCalendarAssignments()->create([
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2026-01-01',
        'status' => EmployeeHolidayCalendarAssignmentStatus::Active,
    ]);
}

function attendanceDayHoliday(HolidayCalendar $calendar, array $attributes = []): Holiday
{
    return Holiday::factory()->create(array_merge([
        'company_id' => $calendar->company_id,
        'holiday_calendar_id' => $calendar->id,
        'date' => '2026-10-05',
        'name' => 'Founders Day',
        'type' => HolidayType::Public,
        'status' => HolidayStatus::Active,
        'is_optional' => false,
    ], $attributes));
}

function attendanceDayResolver(): AttendanceDayResolver
{
    return new AttendanceDayResolver(app(WorkScheduleResolver::class), app(HolidayResolver::class), app(ApprovedLeaveResolver::class));
}

function attendanceDayAddLeave(
    Employee $employee,
    string $amount,
    LeaveRequestDayType $dayType,
    LeaveRequestStatus $status = LeaveRequestStatus::Approved,
    string $date = '2026-10-05',
): LeaveRequestDay {
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-01-01',
    ]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $assignment->leave_type_id,
        'leave_policy_id' => $assignment->leave_policy_id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);
    $reviewer = in_array($status, [LeaveRequestStatus::Approved, LeaveRequestStatus::Rejected], true) ? User::factory()->create() : null;
    $request = LeaveRequest::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $assignment->leave_type_id,
        'leave_policy_id' => $assignment->leave_policy_id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'leave_balance_account_id' => $account->id,
        'start_date' => $date,
        'end_date' => $date,
        'requested_days' => $amount === '0.00' ? '1.00' : $amount,
        'status' => $status,
        'reviewed_by' => $reviewer?->id,
        'reviewed_at' => $reviewer ? now() : null,
        'review_note' => $status === LeaveRequestStatus::Rejected ? 'Not approved' : null,
        'cancelled_by' => $status === LeaveRequestStatus::Cancelled ? User::factory()->create()->id : null,
        'cancelled_at' => $status === LeaveRequestStatus::Cancelled ? now() : null,
        'cancellation_reason' => $status === LeaveRequestStatus::Cancelled ? 'Cancelled by employee' : null,
    ]);

    return LeaveRequestDay::query()->create([
        'company_id' => $employee->company_id,
        'leave_request_id' => $request->id,
        'employee_id' => $employee->id,
        'leave_date' => $date,
        'day_type' => $dayType,
        'requested_amount' => $amount,
    ]);
}

function attendanceDayDate(string $timezone = 'UTC'): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-05', $timezone);
}

test('working schedule without a holiday resolves as working day and retains shift expectations', function () {
    [, $employee, $schedule, $shift] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved)->toBeInstanceOf(ResolvedAttendanceDay::class)
        ->and($resolved->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($resolved->workSchedule()?->shift?->is($shift))->toBeTrue()
        ->and($resolved->workSchedule()?->expectedWorkingMinutes)->toBe(480)
        ->and($resolved->holiday()->isHoliday())->toBeFalse();
});

test('off schedule without a holiday resolves as off day and retains schedule result', function () {
    [, $employee, $schedule] = attendanceDayFixture(mondayIsWorking: false);
    attendanceDayAssignSchedule($employee, $schedule);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::OffDay)
        ->and($resolved->workSchedule())->not->toBeNull()
        ->and($resolved->workSchedule()?->scheduleDay->day_type)->toBe(WorkScheduleDayType::Off)
        ->and($resolved->workSchedule()?->expectedWorkingMinutes)->toBe(0);
});

test('holiday takes precedence for both working and off schedule days', function () {
    foreach ([true, false] as $mondayIsWorking) {
        [$company, $employee, $schedule] = attendanceDayFixture(mondayIsWorking: $mondayIsWorking);
        attendanceDayAssignSchedule($employee, $schedule);
        $calendar = attendanceDayCalendar($company);
        attendanceDayAssignCalendar($employee, $calendar);
        $holiday = attendanceDayHoliday($calendar);

        $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

        expect($resolved->status())->toBe(AttendanceDayStatus::Holiday)
            ->and($resolved->workSchedule())->not->toBeNull()
            ->and($resolved->holiday()->holiday?->is($holiday))->toBeTrue();
    }
});

test('missing schedule takes precedence but preserves an applicable holiday result', function () {
    [$company, $employee] = attendanceDayFixture();
    $calendar = attendanceDayCalendar($company);
    attendanceDayAssignCalendar($employee, $calendar);
    $holiday = attendanceDayHoliday($calendar);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::NoSchedule)
        ->and($resolved->workSchedule())->toBeNull()
        ->and($resolved->holiday()->isHoliday())->toBeTrue()
        ->and($resolved->holiday()->holiday?->is($holiday))->toBeTrue();
});

test('missing holiday assignment and inactive holidays do not override schedule classification', function () {
    [$company, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);

    $withoutCalendar = attendanceDayResolver()->resolve($employee, attendanceDayDate());
    $calendar = attendanceDayCalendar($company);
    attendanceDayAssignCalendar($employee, $calendar);
    attendanceDayHoliday($calendar, ['status' => HolidayStatus::Inactive]);
    $withInactiveHoliday = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($withoutCalendar->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($withoutCalendar->holiday()->calendar)->toBeNull()
        ->and($withInactiveHoliday->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($withInactiveHoliday->holiday()->isHoliday())->toBeFalse();
});

test('optional active holiday is classified as holiday without applying eligibility rules', function () {
    [$company, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    $calendar = attendanceDayCalendar($company);
    attendanceDayAssignCalendar($employee, $calendar);
    attendanceDayHoliday($calendar, ['is_optional' => true]);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::Holiday)
        ->and($resolved->holiday()->isOptional())->toBeTrue();
});

test('no schedule and invalid configurations remain explicit', function () {
    [, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', DayOfWeek::Monday->value)
        ->update(['day_type' => WorkScheduleDayType::Working->value, 'shift_id' => null]);

    expect(fn () => attendanceDayResolver()->resolve($employee, attendanceDayDate()))
        ->toThrow(InvalidWorkScheduleConfigurationException::class);

    [, $employeeWithInvalidCalendar, $validSchedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employeeWithInvalidCalendar, $validSchedule);
    $calendar = attendanceDayCalendar($employeeWithInvalidCalendar->company);
    attendanceDayAssignCalendar($employeeWithInvalidCalendar, $calendar);
    $calendar->update(['status' => HolidayCalendarStatus::Inactive]);

    expect(fn () => attendanceDayResolver()->resolve($employeeWithInvalidCalendar, attendanceDayDate()))
        ->toThrow(InvalidHolidayCalendarConfigurationException::class);
});

test('employee local calendar date is preserved through both underlying resolvers', function () {
    [, $employee, $schedule] = attendanceDayFixture('Asia/Kolkata');
    attendanceDayAssignSchedule($employee, $schedule);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate('Asia/Kolkata'));

    expect($resolved->date()->toDateString())->toBe('2026-10-05')
        ->and($resolved->workSchedule()?->date->toDateString())->toBe('2026-10-05')
        ->and($resolved->holiday()->date->toDateString())->toBe('2026-10-05');
});

test('approved full-day leave covers a normal working-day obligation', function () {
    [, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    $leaveDay = attendanceDayAddLeave($employee, '1.00', LeaveRequestDayType::FullDay);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::OnLeave)
        ->and($resolved->approvedLeave()->hasApprovedLeave())->toBeTrue()
        ->and($resolved->approvedLeave()->leaveRequestDay?->is($leaveDay))->toBeTrue();
});

test('approved half-day leave covers a scheduled half-day obligation', function () {
    [, $employee, $schedule, $shift] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', DayOfWeek::Monday->value)
        ->update(['day_type' => WorkScheduleDayType::HalfDay->value, 'shift_id' => $shift->id]);
    attendanceDayAddLeave($employee, '0.50', LeaveRequestDayType::HalfDay);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::OnLeave)
        ->and($resolved->workSchedule()?->dayType)->toBe(WorkScheduleDayType::HalfDay);
});

test('half-day approved leave retains context without overriding a full working day', function () {
    [, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    $leaveDay = attendanceDayAddLeave($employee, '0.50', LeaveRequestDayType::HalfDay);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($resolved->approvedLeave()->hasApprovedLeave())->toBeTrue()
        ->and($resolved->approvedLeave()->requestedAmount)->toBe('0.50')
        ->and($resolved->approvedLeave()->leaveRequestDay?->is($leaveDay))->toBeTrue();
});

test('holiday, off-day, and missing-schedule precedence remain above approved leave', function () {
    [$company, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    attendanceDayAddLeave($employee, '1.00', LeaveRequestDayType::FullDay);
    $calendar = attendanceDayCalendar($company);
    attendanceDayAssignCalendar($employee, $calendar);
    attendanceDayHoliday($calendar);

    expect(attendanceDayResolver()->resolve($employee, attendanceDayDate())->status())->toBe(AttendanceDayStatus::Holiday);

    [, $offEmployee, $offSchedule] = attendanceDayFixture(mondayIsWorking: false);
    attendanceDayAssignSchedule($offEmployee, $offSchedule);
    attendanceDayAddLeave($offEmployee, '1.00', LeaveRequestDayType::FullDay);
    expect(attendanceDayResolver()->resolve($offEmployee, attendanceDayDate())->status())->toBe(AttendanceDayStatus::OffDay);

    [, $unassignedEmployee] = attendanceDayFixture();
    attendanceDayAddLeave($unassignedEmployee, '1.00', LeaveRequestDayType::FullDay);
    $withoutSchedule = attendanceDayResolver()->resolve($unassignedEmployee, attendanceDayDate());
    expect($withoutSchedule->status())->toBe(AttendanceDayStatus::NoSchedule)
        ->and($withoutSchedule->approvedLeave()->hasApprovedLeave())->toBeTrue();
});

test('pending rejected and cancelled leave do not affect the working-day classification', function (LeaveRequestStatus $status) {
    [, $employee, $schedule] = attendanceDayFixture();
    attendanceDayAssignSchedule($employee, $schedule);
    attendanceDayAddLeave($employee, '1.00', LeaveRequestDayType::FullDay, $status);

    $resolved = attendanceDayResolver()->resolve($employee, attendanceDayDate());

    expect($resolved->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($resolved->approvedLeave()->hasApprovedLeave())->toBeFalse();
})->with([
    'pending' => LeaveRequestStatus::Pending,
    'rejected' => LeaveRequestStatus::Rejected,
    'cancelled' => LeaveRequestStatus::Cancelled,
]);

test('classification is deterministic and resolution does not mutate domain records', function () {
    [$company, $employee, $schedule] = attendanceDayFixture();
    $scheduleAssignment = attendanceDayAssignSchedule($employee, $schedule);
    $calendar = attendanceDayCalendar($company);
    $calendarAssignment = attendanceDayAssignCalendar($employee, $calendar);
    $holiday = attendanceDayHoliday($calendar);
    $before = [
        EmployeeWorkScheduleAssignment::query()->count(),
        EmployeeHolidayCalendarAssignment::query()->count(),
        WorkSchedule::query()->count(),
        WorkScheduleDay::query()->count(),
        Shift::query()->count(),
        HolidayCalendar::query()->count(),
        Holiday::withTrashed()->count(),
        $scheduleAssignment->updated_at?->toISOString(),
        $calendarAssignment->updated_at?->toISOString(),
        $holiday->updated_at?->toISOString(),
    ];
    $resolver = attendanceDayResolver();

    $first = $resolver->resolve($employee, attendanceDayDate());
    $second = $resolver->resolve($employee, attendanceDayDate());
    $after = [
        EmployeeWorkScheduleAssignment::query()->count(),
        EmployeeHolidayCalendarAssignment::query()->count(),
        WorkSchedule::query()->count(),
        WorkScheduleDay::query()->count(),
        Shift::query()->count(),
        HolidayCalendar::query()->count(),
        Holiday::withTrashed()->count(),
        $scheduleAssignment->fresh()->updated_at?->toISOString(),
        $calendarAssignment->fresh()->updated_at?->toISOString(),
        $holiday->fresh()->updated_at?->toISOString(),
    ];

    expect($first->status())->toBe(AttendanceDayStatus::Holiday)
        ->and($second->status())->toBe($first->status())
        ->and($first->employee()->is($employee))->toBeTrue()
        ->and($first->date()->toDateString())->toBe('2026-10-05')
        ->and($first->workSchedule()?->workSchedule->is($schedule))->toBeTrue()
        ->and($first->holiday()->holiday?->is($holiday))->toBeTrue()
        ->and($after)->toBe($before);
});
