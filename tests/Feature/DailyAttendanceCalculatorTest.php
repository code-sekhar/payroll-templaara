<?php

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendancePolicyStatus;
use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DayOfWeek;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkScheduleDayType;
use App\Exceptions\Attendance\InvalidAttendancePolicyConfigurationException;
use App\Exceptions\Attendance\NoAttendancePolicyAssignmentException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
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
use App\Services\Attendance\AttendancePunchPairer;
use App\Services\Attendance\CalculatedDailyAttendance;
use App\Services\Attendance\DailyAttendanceCalculator;
use App\Services\Attendance\PunchPair;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return array{Company, Employee, WorkSchedule, Shift, AttendancePolicy} */
function dailyAttendanceFixture(
    string $timezone = 'Asia/Kolkata',
    bool $hasSchedule = true,
    WorkScheduleDayType $dayType = WorkScheduleDayType::Working,
    string $startTime = '09:00',
    string $endTime = '18:00',
    int $breakMinutes = 60,
    array $policyAttributes = [],
    string $scheduleDate = '2026-10-05',
): array {
    $company = Company::factory()->create(['timezone' => $timezone]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $schedule = WorkSchedule::factory()->create(['company_id' => $company->id]);
    $shift = $company->shifts()->create([
        'name' => 'Test Shift',
        'code' => 'TST-'.fake()->unique()->numerify('#####'),
        'start_time' => $startTime,
        'end_time' => $endTime,
        'break_duration_minutes' => $breakMinutes,
        'grace_period_minutes' => 0,
        'is_overnight' => Shift::isOvernightTime($startTime, $endTime),
        'status' => 'active',
    ]);

    if ($hasSchedule) {
        EmployeeWorkScheduleAssignment::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-01-01',
            'status' => EmployeeWorkScheduleAssignmentStatus::Active,
        ]);

        WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
            ->where('day_of_week', CarbonImmutable::parse($scheduleDate)->dayOfWeekIso)
            ->update([
                'day_type' => $dayType->value,
                'shift_id' => $dayType === WorkScheduleDayType::Off ? null : $shift->id,
            ]);
    }

    $policy = AttendancePolicy::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => AttendancePolicyStatus::Active,
        'grace_in_minutes' => 10,
        'late_after_minutes' => 10,
        'grace_out_minutes' => 10,
        'early_leave_after_minutes' => 10,
        'half_day_before_minutes' => 0,
        'absent_before_minutes' => 0,
        'overtime_enabled' => false,
        'overtime_after_minutes' => 0,
    ], $policyAttributes));

    EmployeeAttendancePolicyAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_id' => $policy->id,
        'effective_from' => '2026-01-01',
        'status' => EmployeeAttendancePolicyAssignmentStatus::Active,
    ]);

    return [$company, $employee, $schedule, $shift, $policy];
}

function dailyAttendancePunch(
    Employee $employee,
    AttendancePunchType $type,
    string $localDateTime,
    AttendancePunchSource $source = AttendancePunchSource::Web,
    ?string $externalReference = null,
): AttendancePunch {
    $timezone = $employee->company->timezone;

    return AttendancePunch::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'punch_type' => $type,
        'punched_at' => CarbonImmutable::parse($localDateTime, $timezone)->utc(),
        'timezone' => $timezone,
        'source' => $source,
        'external_reference' => $externalReference,
    ]);
}

function dailyAttendanceHoliday(Employee $employee, string $date = '2026-10-05'): Holiday
{
    $calendar = HolidayCalendar::factory()->create([
        'company_id' => $employee->company_id,
        'year' => (int) CarbonImmutable::parse($date)->format('Y'),
        'status' => HolidayCalendarStatus::Active,
    ]);
    EmployeeHolidayCalendarAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => $date,
        'effective_to' => $date,
        'status' => EmployeeHolidayCalendarAssignmentStatus::Active,
    ]);

    return Holiday::factory()->create([
        'company_id' => $employee->company_id,
        'holiday_calendar_id' => $calendar->id,
        'date' => $date,
        'name' => 'Test Holiday',
        'type' => HolidayType::Company,
        'status' => HolidayStatus::Active,
    ]);
}

function dailyAttendanceCalculate(Employee $employee, string $date = '2026-10-05'): CalculatedDailyAttendance
{
    return app(DailyAttendanceCalculator::class)->calculate($employee, CarbonImmutable::parse($date, $employee->company->timezone));
}

function dailyAttendanceSetDay(WorkSchedule $schedule, string $date, WorkScheduleDayType $dayType, ?Shift $shift): void
{
    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', CarbonImmutable::parse($date)->dayOfWeekIso)
        ->update([
            'day_type' => $dayType->value,
            'shift_id' => $dayType === WorkScheduleDayType::Off ? null : $shift?->id,
        ]);
}

function dailyAttendanceAttachLeave(
    Employee $employee,
    string $amount = '1.00',
    LeaveRequestDayType $dayType = LeaveRequestDayType::FullDay,
    LeaveRequestStatus $status = LeaveRequestStatus::Approved,
    string $date = '2026-10-05',
    bool $paid = true,
): LeaveRequestDay {
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-01-01',
    ]);
    $assignment->leaveType()->update(['is_paid' => $paid]);
    $account = EmployeeLeaveBalanceAccount::factory()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'leave_type_id' => $assignment->leave_type_id,
        'leave_policy_id' => $assignment->leave_policy_id,
        'employee_leave_policy_assignment_id' => $assignment->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);
    $reviewer = in_array($status, [LeaveRequestStatus::Approved, LeaveRequestStatus::Rejected], true)
        ? User::factory()->create()
        : null;
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
        'cancellation_reason' => $status === LeaveRequestStatus::Cancelled ? 'Cancelled' : null,
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

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'Asia/Kolkata'));
});

test('a valid working day exposes schedule policy first in last out and expected minutes', function () {
    [, $employee] = dailyAttendanceFixture();
    $checkIn = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:05');
    $checkOut = dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:01');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($result->employee->is($employee))->toBeTrue()
        ->and($result->attendanceDate->toDateString())->toBe('2026-10-05')
        ->and($result->resolvedAttendanceDay->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($result->resolvedAttendancePolicy)->not->toBeNull()
        ->and($result->expectedStart?->format('H:i'))->toBe('09:00')
        ->and($result->expectedEnd?->format('H:i'))->toBe('18:00')
        ->and($result->expectedMinutes)->toBe(480)
        ->and($result->firstCheckIn?->equalTo($checkIn->punched_at))->toBeTrue()
        ->and($result->lastCheckOut?->equalTo($checkOut->punched_at))->toBeTrue()
        ->and($result->workedMinutes)->toBe(536)
        ->and(count($result->punchPairs))->toBe(1)
        ->and(count($result->punches))->toBe(2);
});

test('multiple sessions add paired minutes without counting breaks', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 12:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 13:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($result->workedMinutes)->toBe(480)
        ->and(array_map(fn ($pair): int => $pair->workedMinutes, $result->punchPairs))->toBe([180, 300])
        ->and($result->firstCheckIn?->setTimezone('Asia/Kolkata')->format('H:i'))->toBe('09:00')
        ->and($result->lastCheckOut?->setTimezone('Asia/Kolkata')->format('H:i'))->toBe('18:00');
});

test('no punches on a working day are absent with zero worked time', function () {
    [, $employee] = dailyAttendanceFixture();

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($result->workedMinutes)->toBe(0)
        ->and($result->firstCheckIn)->toBeNull()
        ->and($result->lastCheckOut)->toBeNull()
        ->and($result->punchPairs)->toBe([]);
});

test('an unmatched check in returns incomplete and is never auto-closed', function () {
    [, $employee] = dailyAttendanceFixture();
    $punch = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($result->workedMinutes)->toBe(0)
        ->and($result->firstCheckIn?->equalTo($punch->punched_at))->toBeTrue()
        ->and($result->lastCheckOut)->toBeNull()
        ->and($result->punches[0]->is($punch))->toBeTrue();
});

test('consecutive in or out punches and an initial check out are incomplete', function (array $events) {
    [, $employee] = dailyAttendanceFixture();
    foreach ($events as [$type, $time]) {
        dailyAttendancePunch($employee, $type, "2026-10-05 {$time}");
    }

    expect(dailyAttendanceCalculate($employee)->status)->toBe(CalculatedAttendanceStatus::Incomplete);
})->with([
    'consecutive check ins' => [[
        [AttendancePunchType::CheckIn, '09:00'],
        [AttendancePunchType::CheckIn, '10:00'],
        [AttendancePunchType::CheckOut, '18:00'],
    ]],
    'consecutive check outs' => [[
        [AttendancePunchType::CheckIn, '09:00'],
        [AttendancePunchType::CheckOut, '12:00'],
        [AttendancePunchType::CheckOut, '18:00'],
    ]],
    'initial check out' => [[[AttendancePunchType::CheckOut, '18:00']]],
    'check out then otherwise valid pair' => [[
        [AttendancePunchType::CheckOut, '08:00'],
        [AttendancePunchType::CheckIn, '09:00'],
        [AttendancePunchType::CheckOut, '18:00'],
    ]],
]);

test('late threshold is strict and reports actual lateness after it is crossed', function () {
    foreach ([['09:10', false, 0], ['09:11', true, 11]] as [$time, $isLate, $minutes]) {
        [, $employee] = dailyAttendanceFixture();
        dailyAttendancePunch($employee, AttendancePunchType::CheckIn, "2026-10-05 {$time}");
        dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
        $result = dailyAttendanceCalculate($employee);

        expect($result->isLate)->toBe($isLate)->and($result->lateMinutes)->toBe($minutes);
    }
});

test('early leave threshold is strict and reports actual early minutes after it is crossed', function () {
    foreach ([['17:50', false, 0], ['17:49', true, 11]] as [$time, $isEarly, $minutes]) {
        [, $employee] = dailyAttendanceFixture();
        dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
        dailyAttendancePunch($employee, AttendancePunchType::CheckOut, "2026-10-05 {$time}");
        $result = dailyAttendanceCalculate($employee);

        expect($result->isEarlyLeave)->toBe($isEarly)->and($result->earlyLeaveMinutes)->toBe($minutes);
    }
});

test('worked-minute classification honors absent half day and present boundaries', function () {
    foreach ([[120, CalculatedAttendanceStatus::Absent], [121, CalculatedAttendanceStatus::HalfDay], [239, CalculatedAttendanceStatus::HalfDay], [240, CalculatedAttendanceStatus::Present]] as [$worked, $status]) {
        [, $employee] = dailyAttendanceFixture(policyAttributes: ['absent_before_minutes' => 120, 'half_day_before_minutes' => 240]);
        dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
        dailyAttendancePunch($employee, AttendancePunchType::CheckOut, CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Kolkata')->addMinutes($worked)->format('Y-m-d H:i'));

        $result = dailyAttendanceCalculate($employee);
        expect($result->workedMinutes)->toBe($worked)->and($result->status)->toBe($status);
    }
});

test('zero half-day threshold leaves positive valid work present', function () {
    [, $employee] = dailyAttendanceFixture(policyAttributes: ['half_day_before_minutes' => 0, 'absent_before_minutes' => 0]);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 09:01');

    expect(dailyAttendanceCalculate($employee)->status)->toBe(CalculatedAttendanceStatus::Present);
});

test('impossible absent and half day thresholds throw a configuration exception', function () {
    [, $employee] = dailyAttendanceFixture(policyAttributes: ['absent_before_minutes' => 240, 'half_day_before_minutes' => 240]);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');

    expect(fn () => dailyAttendanceCalculate($employee))
        ->toThrow(InvalidAttendancePolicyConfigurationException::class);
});

test('overtime is disabled by default and requires extra minutes strictly beyond its threshold', function () {
    foreach ([false, true] as $enabled) {
        foreach ([30, 31] as $extra) {
            [, $employee] = dailyAttendanceFixture(policyAttributes: [
                'overtime_enabled' => $enabled,
                'overtime_after_minutes' => 30,
            ]);
            dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
            dailyAttendancePunch($employee, AttendancePunchType::CheckOut, CarbonImmutable::parse('2026-10-05 18:00', 'Asia/Kolkata')->addMinutes($extra)->format('Y-m-d H:i'));

            $result = dailyAttendanceCalculate($employee);
            expect($result->overtimeMinutes)->toBe($enabled && $extra === 31 ? 31 : 0)
                ->and($result->status)->toBe(CalculatedAttendanceStatus::Present);
        }
    }
});

test('holiday keeps punches but takes precedence without requiring a policy', function () {
    [, $employee] = dailyAttendanceFixture(hasSchedule: true);
    dailyAttendanceHoliday($employee);
    $employee->attendancePolicyAssignments()->delete();
    $punch = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Holiday)
        ->and($result->resolvedAttendancePolicy)->toBeNull()
        ->and($result->punches)->toHaveCount(1)
        ->and($result->punches[0]->is($punch))->toBeTrue()
        ->and($result->overtimeMinutes)->toBe(0)
        ->and($result->workedMinutes)->toBe(0);
});

test('no schedule remains no schedule even when a holiday and raw punches exist', function () {
    [, $employee] = dailyAttendanceFixture(hasSchedule: false);
    $employee->attendancePolicyAssignments()->delete();
    dailyAttendanceHoliday($employee);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::NoSchedule)
        ->and($result->resolvedAttendanceDay->holiday()->isHoliday())->toBeTrue()
        ->and($result->punches)->toHaveCount(1)
        ->and($result->workedMinutes)->toBe(0);
});

test('off day keeps punches but does not classify them as present', function () {
    [, $employee] = dailyAttendanceFixture(dayType: WorkScheduleDayType::Off);
    $employee->attendancePolicyAssignments()->delete();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 10:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::OffDay)
        ->and($result->expectedMinutes)->toBe(0)
        ->and($result->resolvedAttendancePolicy)->toBeNull()
        ->and($result->punches)->toHaveCount(1)
        ->and($result->overtimeMinutes)->toBe(0);
});

test('no schedule is not absence and does not require attendance policy', function () {
    [, $employee] = dailyAttendanceFixture(hasSchedule: false);
    $employee->attendancePolicyAssignments()->delete();

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::NoSchedule)
        ->and($result->resolvedAttendanceDay->status())->toBe(AttendanceDayStatus::NoSchedule)
        ->and($result->resolvedAttendancePolicy)->toBeNull()
        ->and($result->expectedMinutes)->toBe(0);
});

test('future working days are not yet due and future-dated punches are not used', function () {
    [, $employee, $schedule, $shift] = dailyAttendanceFixture();
    dailyAttendanceSetDay($schedule, '2026-10-06', WorkScheduleDayType::Working, $shift);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-06 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 18:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

    $result = dailyAttendanceCalculate($employee, '2026-10-06');

    expect($result->status)->toBe(CalculatedAttendanceStatus::NotYetDue)
        ->and($result->status)->not->toBe(CalculatedAttendanceStatus::Absent)
        ->and($result->punches)->toBe([])
        ->and($result->workedMinutes)->toBe(0);
});

test('future holiday and off day remain calendar classifications', function () {
    [, $holidayEmployee] = dailyAttendanceFixture(scheduleDate: '2026-10-05');
    dailyAttendanceHoliday($holidayEmployee);
    [, $offEmployee, $offSchedule] = dailyAttendanceFixture(dayType: WorkScheduleDayType::Off);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Kolkata'));

    expect(dailyAttendanceCalculate($holidayEmployee, '2026-10-05')->status)->toBe(CalculatedAttendanceStatus::Holiday)
        ->and(dailyAttendanceCalculate($offEmployee, '2026-10-05')->status)->toBe(CalculatedAttendanceStatus::OffDay);
});

test('today without punches remains in progress until the post-shift punch buffer ends', function () {
    [, $employee] = dailyAttendanceFixture();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Kolkata'));

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($result->workedMinutes)->toBe(0)
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
});

test('today open check in remains in progress without inventing a checkout', function () {
    [, $employee] = dailyAttendanceFixture();
    $checkIn = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 14:00:00', 'Asia/Kolkata'));

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($result->firstCheckIn?->equalTo($checkIn->punched_at))->toBeTrue()
        ->and($result->lastCheckOut)->toBeNull()
        ->and($result->punchPairs)->toBe([])
        ->and($result->workedMinutes)->toBe(0)
        ->and(AttendancePunch::query()->where('punch_type', AttendancePunchType::CheckOut)->count())->toBe(0);
});

test('no punches and open check in become final after the shift buffer ends', function () {
    [, $withoutPunches] = dailyAttendanceFixture();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 23:59:59', 'Asia/Kolkata'));
    expect(dailyAttendanceCalculate($withoutPunches)->status)->toBe(CalculatedAttendanceStatus::InProgress);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 00:00:00', 'Asia/Kolkata'));
    expect(dailyAttendanceCalculate($withoutPunches)->status)->toBe(CalculatedAttendanceStatus::Absent);

    [, $openEmployee] = dailyAttendanceFixture();
    dailyAttendancePunch($openEmployee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    expect(dailyAttendanceCalculate($openEmployee)->status)->toBe(CalculatedAttendanceStatus::Incomplete);
});

test('current and future determination follows company local dates at the same instant', function () {
    [, $indiaEmployee] = dailyAttendanceFixture('Asia/Kolkata', scheduleDate: '2026-10-06');
    [, $londonEmployee] = dailyAttendanceFixture('Europe/London', scheduleDate: '2026-10-06');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 22:30:00', 'UTC'));

    $india = dailyAttendanceCalculate($indiaEmployee, '2026-10-06');
    $london = dailyAttendanceCalculate($londonEmployee, '2026-10-06');

    expect($india->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($london->status)->toBe(CalculatedAttendanceStatus::NotYetDue);
});

test('a configured half day schedule continues through normal working day calculation', function () {
    [, $employee] = dailyAttendanceFixture(dayType: WorkScheduleDayType::HalfDay, startTime: '09:00', endTime: '13:00', breakMinutes: 0);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 13:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->resolvedAttendanceDay->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($result->expectedMinutes)->toBe(240)
        ->and($result->status)->toBe(CalculatedAttendanceStatus::Present);
});

test('invalid schedule and attendance policy configuration exceptions propagate', function () {
    [, $employee, $schedule, , $policy] = dailyAttendanceFixture();

    DB::table('work_schedule_days')->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', DayOfWeek::Monday->value)
        ->update(['shift_id' => null]);

    expect(fn () => dailyAttendanceCalculate($employee))->toThrow(InvalidWorkScheduleConfigurationException::class);

    WorkScheduleDay::query()->where('work_schedule_id', $schedule->id)
        ->where('day_of_week', DayOfWeek::Monday->value)
        ->update(['shift_id' => Shift::query()->where('company_id', $employee->company_id)->value('id')]);
    DB::table('attendance_policies')->where('id', $policy->id)->update(['grace_in_minutes' => 20, 'late_after_minutes' => 10]);

    expect(fn () => dailyAttendanceCalculate($employee))->toThrow(InvalidAttendancePolicyConfigurationException::class);
});

test('working day without a policy assignment propagates the missing-policy exception', function () {
    [, $employee] = dailyAttendanceFixture();
    $employee->attendancePolicyAssignments()->delete();

    expect(fn () => dailyAttendanceCalculate($employee))->toThrow(NoAttendancePolicyAssignmentException::class);
});

test('overnight shift includes next-day checkout and returns elapsed worked minutes', function () {
    [, $employee] = dailyAttendanceFixture(startTime: '22:00', endTime: '06:00', breakMinutes: 0);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 21:58');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 06:04');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($result->attendanceDate->toDateString())->toBe('2026-10-05')
        ->and($result->expectedStart?->format('Y-m-d H:i'))->toBe('2026-10-05 22:00')
        ->and($result->expectedEnd?->format('Y-m-d H:i'))->toBe('2026-10-06 06:00')
        ->and($result->lastCheckOut?->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-10-06 06:04')
        ->and($result->workedMinutes)->toBe(486);
});

test('adjacent daytime attendance dates isolate their normal sessions', function () {
    [, $employee, $schedule, $shift] = dailyAttendanceFixture();
    dailyAttendanceSetDay($schedule, '2026-10-06', WorkScheduleDayType::Working, $shift);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-06 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 18:00');

    $octoberFifth = dailyAttendanceCalculate($employee, '2026-10-05');
    $octoberSixth = dailyAttendanceCalculate($employee, '2026-10-06');

    expect($octoberFifth->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($octoberFifth->punches)->toHaveCount(2)
        ->and($octoberFifth->workedMinutes)->toBe(540)
        ->and($octoberSixth->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($octoberSixth->punches)->toHaveCount(2)
        ->and($octoberSixth->workedMinutes)->toBe(540);
});

test('adjacent overnight shifts attribute each after-midnight checkout only to its shift date', function () {
    [, $employee, $schedule, $shift] = dailyAttendanceFixture(startTime: '22:00', endTime: '06:00', breakMinutes: 0);
    dailyAttendanceSetDay($schedule, '2026-10-06', WorkScheduleDayType::Working, $shift);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 21:58');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 06:02');
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-06 21:57');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-07 06:01');

    $firstDate = dailyAttendanceCalculate($employee, '2026-10-05');
    $secondDate = dailyAttendanceCalculate($employee, '2026-10-06');

    expect($firstDate->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($firstDate->punches)->toHaveCount(2)
        ->and($firstDate->workedMinutes)->toBe(484)
        ->and($secondDate->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($secondDate->punches)->toHaveCount(2)
        ->and($secondDate->workedMinutes)->toBe(484)
        ->and($firstDate->punches[0]->id)->not->toBe($secondDate->punches[0]->id);
});

test('overlapping adjacent punch windows assign an event to the nearer shift only', function () {
    [, $employee, $schedule, $shift] = dailyAttendanceFixture(startTime: '18:00', endTime: '08:00', breakMinutes: 0);
    dailyAttendanceSetDay($schedule, '2026-10-06', WorkScheduleDayType::Working, $shift);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-06 12:10');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 12:30');

    $firstDate = dailyAttendanceCalculate($employee, '2026-10-05');
    $secondDate = dailyAttendanceCalculate($employee, '2026-10-06');

    expect($firstDate->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($firstDate->workedMinutes)->toBe(20)
        ->and($secondDate->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($secondDate->punches)->toBeEmpty();
});

test('a punch equally close to adjacent shift intervals makes the day incomplete', function () {
    [, $employee, $schedule, $shift] = dailyAttendanceFixture(startTime: '18:00', endTime: '08:00', breakMinutes: 0);
    dailyAttendanceSetDay($schedule, '2026-10-06', WorkScheduleDayType::Working, $shift);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-06 13:00');

    $result = dailyAttendanceCalculate($employee, '2026-10-05');

    expect($result->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($result->punches)->toHaveCount(1)
        ->and($result->workedMinutes)->toBe(0);
});

test('a valid twelve hour session is not capped', function () {
    [, $employee] = dailyAttendanceFixture(startTime: '18:00', endTime: '06:00', breakMinutes: 0);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 18:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 06:00');

    expect(dailyAttendanceCalculate($employee)->workedMinutes)->toBe(720);
});

test('zero expected work minutes is invalid schedule configuration on a working day', function () {
    [, $employee, , $shift] = dailyAttendanceFixture(startTime: '09:00', endTime: '18:00', breakMinutes: 0);
    $shift->update(['break_duration_minutes' => 540]);

    expect(fn () => dailyAttendanceCalculate($employee))
        ->toThrow(InvalidWorkScheduleConfigurationException::class);
});

test('spring forward and fall back sessions use elapsed instants across DST', function () {
    [, $springEmployee] = dailyAttendanceFixture(
        'America/New_York',
        startTime: '01:30',
        endTime: '03:30',
        breakMinutes: 0,
        scheduleDate: '2026-03-08',
    );
    dailyAttendancePunch($springEmployee, AttendancePunchType::CheckIn, '2026-03-08 01:30');
    dailyAttendancePunch($springEmployee, AttendancePunchType::CheckOut, '2026-03-08 03:30');

    [, $fallEmployee] = dailyAttendanceFixture(
        'America/New_York',
        startTime: '00:30',
        endTime: '02:30',
        breakMinutes: 0,
        scheduleDate: '2026-11-01',
    );
    dailyAttendancePunch($fallEmployee, AttendancePunchType::CheckIn, '2026-11-01T00:30:00-04:00');
    dailyAttendancePunch($fallEmployee, AttendancePunchType::CheckOut, '2026-11-01T02:30:00-05:00');

    expect(dailyAttendanceCalculate($springEmployee, '2026-03-08')->workedMinutes)->toBe(60)
        ->and(dailyAttendanceCalculate($fallEmployee, '2026-11-01')->workedMinutes)->toBe(180);
});

test('reversed pair endpoints are rejected instead of producing negative minutes', function () {
    $checkIn = AttendancePunch::make([
        'punch_type' => AttendancePunchType::CheckIn,
        'punched_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'),
    ]);
    $checkOut = AttendancePunch::make([
        'punch_type' => AttendancePunchType::CheckOut,
        'punched_at' => CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'),
    ]);

    expect(fn () => new PunchPair($checkIn, $checkOut))
        ->toThrow(InvalidArgumentException::class);
});

test('biometric check in and manual check out form a normal pair', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00', AttendancePunchSource::Biometric);
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00', AttendancePunchSource::Manual);

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($result->workedMinutes)->toBe(540)
        ->and($result->punchPairs)->toHaveCount(1);
});

test('external references do not deduplicate identical raw punch events', function () {
    [, $employee] = dailyAttendanceFixture();
    $first = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00:00', AttendancePunchSource::Web, 'web-event-1');
    $second = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00:00', AttendancePunchSource::Web, 'web-event-2');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($result->punches)->toHaveCount(2)
        ->and($result->punches[0]->is($first))->toBeTrue()
        ->and($result->punches[1]->is($second))->toBeTrue();
});

test('punch window includes its six hour boundary and excludes a distant punch', function () {
    [, $employee] = dailyAttendanceFixture();
    $inside = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 03:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 03:01');
    $distant = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 02:59:59');

    $result = dailyAttendanceCalculate($employee);

    expect($result->punches)->toHaveCount(2)
        ->and($result->punches[0]->is($inside))->toBeTrue()
        ->and($result->punches)->not->toContain($distant)
        ->and($result->status)->toBe(CalculatedAttendanceStatus::Present);
});

test('punch window includes the exact end and excludes the following second', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 23:59:00');
    $atEnd = dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-06 00:00:00');
    $outside = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-06 00:00:01');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Present)
        ->and($result->punches)->toHaveCount(2)
        ->and($result->punches[1]->is($atEnd))->toBeTrue()
        ->and($result->punches)->not->toContain($outside)
        ->and($result->workedMinutes)->toBe(1);
});

test('absolute punches compare correctly with company-local schedule times', function () {
    [, $employee] = dailyAttendanceFixture('Asia/Kolkata');
    $checkIn = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $checkOut = dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');

    $result = dailyAttendanceCalculate($employee);

    expect($checkIn->punched_at->utc()->format('H:i'))->toBe('03:30')
        ->and($checkOut->punched_at->utc()->format('H:i'))->toBe('12:30')
        ->and($result->expectedStart?->getTimestamp())->toBe($checkIn->punched_at->getTimestamp())
        ->and($result->workedMinutes)->toBe(540)
        ->and($result->attendanceDate->timezoneName)->toBe('Asia/Kolkata');
});

test('every raw punch source participates in chronological pairing without source-specific math', function () {
    foreach (AttendancePunchSource::cases() as $source) {
        [, $employee] = dailyAttendanceFixture();
        dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00', $source);
        dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00', $source);

        $result = dailyAttendanceCalculate($employee);
        expect($result->status)->toBe(CalculatedAttendanceStatus::Present)
            ->and($result->workedMinutes)->toBe(540);
    }
});

test('same-time punches are ordered deterministically by id', function () {
    [, $employee] = dailyAttendanceFixture();
    $in = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $out = dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 09:00');

    $result = dailyAttendanceCalculate($employee);

    expect(array_map(fn (AttendancePunch $punch): int => $punch->id, $result->punches))->toBe([$in->id, $out->id])
        ->and($result->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($result->punchPairs[0]->workedMinutes)->toBe(0);
});

test('whole elapsed minutes floor seconds without rounding up', function (string $checkIn, string $checkOut, int $expectedMinutes) {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, "2026-10-05 {$checkIn}");
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, "2026-10-05 {$checkOut}");

    expect(dailyAttendanceCalculate($employee)->workedMinutes)->toBe($expectedMinutes);
})->with([
    '59 seconds' => ['09:00:00', '09:00:59', 0],
    '60 seconds' => ['09:00:00', '09:01:00', 1],
    'sub-minute across minute labels' => ['09:00:30', '09:01:29', 0],
    'full minute from non-zero seconds' => ['09:00:30', '09:01:30', 1],
]);

test('late early-leave and overtime calculations floor seconds consistently', function () {
    [, $lateAtTenFiftyNine] = dailyAttendanceFixture();
    dailyAttendancePunch($lateAtTenFiftyNine, AttendancePunchType::CheckIn, '2026-10-05 09:10:59');
    dailyAttendancePunch($lateAtTenFiftyNine, AttendancePunchType::CheckOut, '2026-10-05 18:00:00');
    $tenFiftyNineLate = dailyAttendanceCalculate($lateAtTenFiftyNine);

    [, $lateAtEleven] = dailyAttendanceFixture();
    dailyAttendancePunch($lateAtEleven, AttendancePunchType::CheckIn, '2026-10-05 09:11:00');
    dailyAttendancePunch($lateAtEleven, AttendancePunchType::CheckOut, '2026-10-05 18:00:00');
    $elevenLate = dailyAttendanceCalculate($lateAtEleven);

    [, $earlyAtTenFiftyNine] = dailyAttendanceFixture();
    dailyAttendancePunch($earlyAtTenFiftyNine, AttendancePunchType::CheckIn, '2026-10-05 09:00:00');
    dailyAttendancePunch($earlyAtTenFiftyNine, AttendancePunchType::CheckOut, '2026-10-05 17:49:01');
    $tenFiftyNineEarly = dailyAttendanceCalculate($earlyAtTenFiftyNine);

    [, $overtimeAtThirtyFiftyNine] = dailyAttendanceFixture(policyAttributes: ['overtime_enabled' => true, 'overtime_after_minutes' => 30]);
    dailyAttendancePunch($overtimeAtThirtyFiftyNine, AttendancePunchType::CheckIn, '2026-10-05 09:00:00');
    dailyAttendancePunch($overtimeAtThirtyFiftyNine, AttendancePunchType::CheckOut, '2026-10-05 18:30:59');
    $thirtyFiftyNineOvertime = dailyAttendanceCalculate($overtimeAtThirtyFiftyNine);

    [, $overtimeAtThirtyOne] = dailyAttendanceFixture(policyAttributes: ['overtime_enabled' => true, 'overtime_after_minutes' => 30]);
    dailyAttendancePunch($overtimeAtThirtyOne, AttendancePunchType::CheckIn, '2026-10-05 09:00:00');
    dailyAttendancePunch($overtimeAtThirtyOne, AttendancePunchType::CheckOut, '2026-10-05 18:31:00');
    $thirtyOneOvertime = dailyAttendanceCalculate($overtimeAtThirtyOne);

    expect($tenFiftyNineLate->lateMinutes)->toBe(0)
        ->and($elevenLate->lateMinutes)->toBe(11)
        ->and($tenFiftyNineEarly->earlyLeaveMinutes)->toBe(0)
        ->and($thirtyFiftyNineOvertime->overtimeMinutes)->toBe(0)
        ->and($thirtyOneOvertime->overtimeMinutes)->toBe(31);
});

test('a zero-minute same-timestamp pair is valid and does not create a minute', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 09:00:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($result->workedMinutes)->toBe(0)
        ->and($result->punchPairs[0]->workedMinutes)->toBe(0);
});

test('sub-minute residual seconds are floored to whole elapsed minutes', function () {
    $checkIn = new AttendancePunch;
    $checkIn->setRawAttributes([
        'punch_type' => AttendancePunchType::CheckIn->value,
        'punched_at' => '2026-10-05 09:00:00.500000',
    ]);
    $checkOut = new AttendancePunch;
    $checkOut->setRawAttributes([
        'punch_type' => AttendancePunchType::CheckOut->value,
        'punched_at' => '2026-10-05 09:01:00.000000',
    ]);
    $minutePair = new PunchPair(
        $checkIn,
        $checkOut,
    );

    $checkInAtLaterMicrosecond = new AttendancePunch;
    $checkInAtLaterMicrosecond->setRawAttributes([
        'id' => 1,
        'punch_type' => AttendancePunchType::CheckIn->value,
        'punched_at' => '2026-10-05 09:00:00.900000',
    ]);
    $checkOutAtEarlierMicrosecond = new AttendancePunch;
    $checkOutAtEarlierMicrosecond->setRawAttributes([
        'id' => 2,
        'punch_type' => AttendancePunchType::CheckOut->value,
        'punched_at' => '2026-10-05 09:00:00.100000',
    ]);
    $microsecondOrder = app(AttendancePunchPairer::class)->pair([$checkInAtLaterMicrosecond, $checkOutAtEarlierMicrosecond]);

    expect($minutePair->workedMinutes)->toBe(0)
        ->and($microsecondOrder->isMalformed)->toBeTrue()
        ->and($microsecondOrder->pairs)->toBe([]);
});

test('completed pairs remain visible when a later unmatched check in makes the day incomplete', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 12:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 13:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($result->punchPairs)->toHaveCount(1)
        ->and($result->punchPairs[0]->workedMinutes)->toBe(180)
        ->and($result->workedMinutes)->toBe(180)
        ->and($result->punches)->toHaveCount(3);
});

test('calculation does not alter raw punches and returns an immutable result', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $before = AttendancePunch::query()->orderBy('id')->get()->map(fn (AttendancePunch $punch): array => [
        $punch->id, $punch->employee_id, $punch->punch_type->value, $punch->punched_at->toISOString(), $punch->source->value,
    ])->all();

    $result = dailyAttendanceCalculate($employee);
    $repeatedResult = dailyAttendanceCalculate($employee);
    $after = AttendancePunch::query()->orderBy('id')->get()->map(fn (AttendancePunch $punch): array => [
        $punch->id, $punch->employee_id, $punch->punch_type->value, $punch->punched_at->toISOString(), $punch->source->value,
    ])->all();

    expect($after)->toBe($before)
        ->and($repeatedResult->status)->toBe($result->status)
        ->and($repeatedResult->workedMinutes)->toBe($result->workedMinutes)
        ->and(array_map(fn ($pair): int => $pair->workedMinutes, $repeatedResult->punchPairs))->toBe(array_map(fn ($pair): int => $pair->workedMinutes, $result->punchPairs))
        ->and((new ReflectionClass($result))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($result->punchPairs[0]))->isReadOnly())->toBeTrue()
        ->and($result->punches)->toHaveCount(2)
        ->and(Schema::hasTable('daily_attendances'))->toBeFalse()
        ->and(Schema::hasTable('attendance_records'))->toBeFalse();
});

test('approved full-day leave resolves on leave without punches or an attendance policy', function () {
    [, $employee] = dailyAttendanceFixture();
    $leaveDay = dailyAttendanceAttachLeave($employee, '1.00', paid: false);
    $employee->attendancePolicyAssignments()->delete();

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($result->resolvedAttendancePolicy)->toBeNull()
        ->and($result->expectedMinutes)->toBe(0)
        ->and($result->workedMinutes)->toBe(0)
        ->and($result->leaveAmount())->toBe('1.00')
        ->and($result->isPaidLeave())->toBeFalse()
        ->and($result->approvedLeave()->leaveRequestDay?->is($leaveDay))->toBeTrue()
        ->and($result->approvedLeave()->leaveRequest)->not->toBeNull()
        ->and($result->approvedLeave()->leaveType)->not->toBeNull()
        ->and($result->approvedLeave()->leavePolicy)->not->toBeNull()
        ->and($result->approvedLeave()->balanceAccount)->not->toBeNull()
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
});

test('punches on a full-day leave remain visible without changing leave classification', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($employee);
    $in = dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    $out = dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 18:00');
    $employee->attendancePolicyAssignments()->delete();

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($result->punches)->toHaveCount(2)
        ->and($result->punchPairs)->toHaveCount(1)
        ->and($result->punches[0]->is($in))->toBeTrue()
        ->and($result->punches[1]->is($out))->toBeTrue()
        ->and($result->workedMinutes)->toBe(540)
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
});

test('approved half leave on a scheduled half day resolves as full-day leave', function () {
    [, $employee] = dailyAttendanceFixture(
        dayType: WorkScheduleDayType::HalfDay,
        startTime: '09:00',
        endTime: '13:00',
        breakMinutes: 0,
    );
    dailyAttendanceAttachLeave($employee, '0.50', LeaveRequestDayType::HalfDay);
    $employee->attendancePolicyAssignments()->delete();

    $result = dailyAttendanceCalculate($employee);

    expect($result->resolvedAttendanceDay->status())->toBe(AttendanceDayStatus::OnLeave)
        ->and($result->status)->toBe(CalculatedAttendanceStatus::OnLeave)
        ->and($result->leaveAmount())->toBe('0.50')
        ->and($result->expectedMinutes)->toBe(0);
});

test('partial leave reduces a full work obligation and exact or excess work qualifies as partial leave', function (int $workedMinutes) {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($employee, '0.50', LeaveRequestDayType::HalfDay);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Kolkata')->addMinutes($workedMinutes)->format('Y-m-d H:i'));

    $result = dailyAttendanceCalculate($employee);

    expect($result->resolvedAttendanceDay->status())->toBe(AttendanceDayStatus::WorkingDay)
        ->and($result->status)->toBe(CalculatedAttendanceStatus::PartialLeave)
        ->and($result->expectedMinutes)->toBe(240)
        ->and($result->workedMinutes)->toBe($workedMinutes)
        ->and($result->leaveAmount())->toBe('0.50')
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
})->with([240, 250]);

test('partial leave uses integer floor for the remaining obligation', function () {
    [, $employee] = dailyAttendanceFixture(breakMinutes: 59);
    dailyAttendanceAttachLeave($employee, '0.50', LeaveRequestDayType::HalfDay);

    $result = dailyAttendanceCalculate($employee);

    expect($result->expectedMinutes)->toBe(240); // 481 / 2, rounded down.
});

test('partial leave with insufficient paired work is half day without lateness or overtime', function () {
    [, $employee] = dailyAttendanceFixture(policyAttributes: [
        'overtime_enabled' => true,
        'overtime_after_minutes' => 0,
    ]);
    dailyAttendanceAttachLeave($employee, '0.50', LeaveRequestDayType::HalfDay);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 10:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 12:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::HalfDay)
        ->and($result->leaveAmount())->toBe('0.50')
        ->and($result->approvedLeave()->hasApprovedLeave())->toBeTrue()
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
});

test('partial leave with no completed punch pair or malformed punches is incomplete', function (array $events) {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($employee, '0.50', LeaveRequestDayType::HalfDay);
    foreach ($events as [$type, $time]) {
        dailyAttendancePunch($employee, $type, "2026-10-05 {$time}");
    }

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Incomplete)
        ->and($result->leaveAmount())->toBe('0.50')
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
})->with([
    'no punches' => [[]],
    'unmatched check in' => [[[AttendancePunchType::CheckIn, '09:00']]],
    'malformed consecutive check ins' => [[
        [AttendancePunchType::CheckIn, '09:00'],
        [AttendancePunchType::CheckIn, '10:00'],
        [AttendancePunchType::CheckOut, '12:00'],
    ]],
]);

test('future and current partial leave retain context without premature final classification', function () {
    [, $futureEmployee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($futureEmployee, '0.50', LeaveRequestDayType::HalfDay);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Kolkata'));
    $future = dailyAttendanceCalculate($futureEmployee);

    [, $currentEmployee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($currentEmployee, '0.50', LeaveRequestDayType::HalfDay);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Kolkata'));
    $current = dailyAttendanceCalculate($currentEmployee);

    expect($future->status)->toBe(CalculatedAttendanceStatus::NotYetDue)
        ->and($future->leaveAmount())->toBe('0.50')
        ->and($future->lateMinutes)->toBe(0)
        ->and($future->earlyLeaveMinutes)->toBe(0)
        ->and($future->overtimeMinutes)->toBe(0)
        ->and($current->status)->toBe(CalculatedAttendanceStatus::InProgress)
        ->and($current->leaveAmount())->toBe('0.50')
        ->and($current->lateMinutes)->toBe(0)
        ->and($current->earlyLeaveMinutes)->toBe(0)
        ->and($current->overtimeMinutes)->toBe(0);
});

test('unapproved leave does not affect normal working day calculation', function (LeaveRequestStatus $status) {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($employee, '1.00', LeaveRequestDayType::FullDay, $status);

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::Absent)
        ->and($result->approvedLeave()->hasApprovedLeave())->toBeFalse()
        ->and($result->leaveAmount())->toBe('0.00');
})->with([
    'pending' => LeaveRequestStatus::Pending,
    'rejected' => LeaveRequestStatus::Rejected,
    'cancelled' => LeaveRequestStatus::Cancelled,
]);

test('holiday off day and no schedule precedence remain ahead of approved leave', function () {
    [, $holidayEmployee] = dailyAttendanceFixture();
    dailyAttendanceHoliday($holidayEmployee);
    dailyAttendanceAttachLeave($holidayEmployee);

    [, $offEmployee] = dailyAttendanceFixture(dayType: WorkScheduleDayType::Off);
    dailyAttendanceAttachLeave($offEmployee);

    [, $noScheduleEmployee] = dailyAttendanceFixture(hasSchedule: false);
    dailyAttendanceAttachLeave($noScheduleEmployee);

    expect(dailyAttendanceCalculate($holidayEmployee)->status)->toBe(CalculatedAttendanceStatus::Holiday)
        ->and(dailyAttendanceCalculate($offEmployee)->status)->toBe(CalculatedAttendanceStatus::OffDay)
        ->and(dailyAttendanceCalculate($noScheduleEmployee)->status)->toBe(CalculatedAttendanceStatus::NoSchedule);
});

test('partial leave reuses pairing to sum work sessions while excluding their gap', function () {
    [, $employee] = dailyAttendanceFixture();
    dailyAttendanceAttachLeave($employee, '0.50', LeaveRequestDayType::HalfDay);
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 09:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 11:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckIn, '2026-10-05 12:00');
    dailyAttendancePunch($employee, AttendancePunchType::CheckOut, '2026-10-05 14:00');

    $result = dailyAttendanceCalculate($employee);

    expect($result->status)->toBe(CalculatedAttendanceStatus::PartialLeave)
        ->and($result->workedMinutes)->toBe(240)
        ->and(array_map(fn (PunchPair $pair): int => $pair->workedMinutes, $result->punchPairs))->toBe([120, 120]);
});
