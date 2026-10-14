<?php

use App\Actions\SeedCompanyRoles;
use App\Actions\WorkSchedules\CreateWorkSchedule;
use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function workScheduleModuleUser(string $role): array
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

/** @return array{Shift, Shift} */
function workScheduleModuleShifts(Company $company, string $suffix = '1'): array
{
    $regular = $company->shifts()->create([
        'name' => 'General '.$suffix, 'code' => 'GENERAL-'.$suffix, 'start_time' => '09:00', 'end_time' => '18:00',
        'break_duration_minutes' => 60, 'grace_period_minutes' => 10, 'is_overnight' => false, 'status' => 'active',
    ]);
    $half = $company->shifts()->create([
        'name' => 'Half day '.$suffix, 'code' => 'HALF-'.$suffix, 'start_time' => '09:00', 'end_time' => '13:00',
        'break_duration_minutes' => 0, 'grace_period_minutes' => 0, 'is_overnight' => false, 'status' => 'active',
    ]);

    return [$regular, $half];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function workScheduleModulePayload(Shift $regular, Shift $half, array $overrides = []): array
{
    $days = [];
    foreach (range(1, 7) as $day) {
        $days[] = [
            'day_of_week' => $day,
            'day_type' => $day <= 5 ? 'working' : ($day === 6 ? 'half_day' : 'off'),
            'shift_id' => $day <= 5 ? $regular->id : ($day === 6 ? $half->id : null),
        ];
    }

    return array_merge([
        'name' => 'Standard 6 Day',
        'code' => 'STANDARD-6-DAY',
        'description' => 'Regular six-day pattern',
        'days' => $days,
    ], $overrides);
}

test('HR admin creates a complete schedule and list summaries derive from referenced shifts', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    $payload = workScheduleModulePayload($regular, $half);

    $this->actingAs($hrAdmin)->post(route('companies.work-schedules.store', $company), $payload)
        ->assertRedirect(route('companies.work-schedules.index', $company));
    $schedule = $company->workSchedules()->with('days.shift')->firstOrFail();

    expect($schedule->days)->toHaveCount(7)
        ->and($schedule->creator->is($hrAdmin))->toBeTrue()
        ->and($schedule->updater->is($hrAdmin))->toBeTrue()
        ->and($schedule->days->where('day_type', WorkScheduleDayType::Off))->toHaveCount(1);

    $this->get(route('companies.work-schedules.index', $company))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('work-schedules/index', false)
            ->has('schedules.data', 1)
            ->where('schedules.data.0.working_days_count', 6)
            ->where('schedules.data.0.weekly_minutes', 2640)
            ->where('schedules.data.0.code', 'STANDARD-6-DAY'));
});

test('company scoped routes and shift selectors block cross tenant schedule access', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    $payload = workScheduleModulePayload($regular, $half);
    $this->actingAs($hrAdmin)->post(route('companies.work-schedules.store', $company), $payload)->assertRedirect();
    $schedule = $company->workSchedules()->firstOrFail();
    $otherCompany = Company::factory()->create();
    [$foreignShift] = workScheduleModuleShifts($otherCompany, '2');

    $this->get(route('companies.work-schedules.index', $otherCompany))->assertNotFound();
    $this->get(route('companies.work-schedules.edit', [$otherCompany, $schedule]))->assertNotFound();
    $this->put(route('companies.work-schedules.update', [$otherCompany, $schedule]), $payload)->assertNotFound();
    $this->delete(route('companies.work-schedules.destroy', [$otherCompany, $schedule]))->assertNotFound();
    $payload['days'][0]['shift_id'] = $foreignShift->id;
    $this->post(route('companies.work-schedules.store', $company), $payload)->assertSessionHasErrors('days.0.shift_id');
    expect($company->workSchedules()->count())->toBe(1);
});

test('weekly configuration requires exactly seven distinct days and valid off working and half day shifts', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    $route = route('companies.work-schedules.store', $company);
    $payload = workScheduleModulePayload($regular, $half);
    $this->actingAs($hrAdmin)->post($route, [...$payload, 'days' => array_slice($payload['days'], 0, 6)])->assertSessionHasErrors('days');

    $duplicate = $payload;
    $duplicate['days'][6]['day_of_week'] = 6;
    $this->post($route, $duplicate)->assertSessionHasErrors(['days', 'days.6.day_of_week']);

    $offWithShift = $payload;
    $offWithShift['days'][6]['shift_id'] = $regular->id;
    $this->post($route, $offWithShift)->assertSessionHasErrors('days.6.shift_id');

    $workingWithoutShift = $payload;
    $workingWithoutShift['days'][0]['shift_id'] = null;
    $this->post($route, $workingWithoutShift)->assertSessionHasErrors('days.0.shift_id');

    $halfDayWithoutShift = $payload;
    $halfDayWithoutShift['days'][5]['shift_id'] = null;
    $this->post($route, $halfDayWithoutShift)->assertSessionHasErrors('days.5.shift_id');

    expect($company->workSchedules()->count())->toBe(0)->and($company->workSchedules()->withCount('days')->get())->toHaveCount(0);
});

test('inactive shifts cannot be newly selected and expected minutes cannot be submitted', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    $regular->update(['status' => 'inactive']);
    $payload = workScheduleModulePayload($regular, $half);

    $this->actingAs($hrAdmin)->post(route('companies.work-schedules.store', $company), $payload)
        ->assertSessionHasErrors('days.0.shift_id');
    $payload['days'][0]['shift_id'] = $half->id;
    $payload['days'][0]['expected_minutes'] = 999;
    $this->post(route('companies.work-schedules.store', $company), $payload)
        ->assertSessionHasErrors('days.0.expected_minutes');
    expect($company->workSchedules()->exists())->toBeFalse();
});

test('schedule update changes existing weekday rows without adding duplicates', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    $this->actingAs($hrAdmin)->post(route('companies.work-schedules.store', $company), workScheduleModulePayload($regular, $half))->assertRedirect();
    $schedule = $company->workSchedules()->firstOrFail();
    $payload = workScheduleModulePayload($regular, $half, ['name' => 'Standard 5 Day', 'code' => 'STANDARD-5-DAY']);
    $payload['days'][5] = ['day_of_week' => 6, 'day_type' => 'off', 'shift_id' => null];
    $payload['days'][6] = ['day_of_week' => 7, 'day_type' => 'off', 'shift_id' => null];

    $this->put(route('companies.work-schedules.update', [$company, $schedule]), $payload)
        ->assertRedirect(route('companies.work-schedules.index', $company));
    expect($schedule->fresh()->name)->toBe('Standard 5 Day')
        ->and($schedule->days()->count())->toBe(7)
        ->and($schedule->days()->where('day_type', 'off')->count())->toBe(2)
        ->and($schedule->fresh()->updated_by)->toBe($hrAdmin->id);
});

test('schedules are created atomically if a day insert fails', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    WorkScheduleDay::creating(function (WorkScheduleDay $day): void {
        if ($day->day_of_week === DayOfWeek::Thursday) {
            throw new RuntimeException('Simulated day persistence failure.');
        }
    });

    expect(fn () => app(CreateWorkSchedule::class)->handle($company, $hrAdmin, workScheduleModulePayload($regular, $half)))
        ->toThrow(RuntimeException::class);
    expect($company->workSchedules()->withTrashed()->count())->toBe(0)
        ->and(WorkScheduleDay::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('schedule names and codes are unique per company and reusable across tenants', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    [$regular, $half] = workScheduleModuleShifts($company);
    $route = route('companies.work-schedules.store', $company);
    $payload = workScheduleModulePayload($regular, $half);
    $this->actingAs($hrAdmin)->post($route, $payload)->assertRedirect();
    $this->post($route, workScheduleModulePayload($regular, $half, ['name' => 'Another Name']))->assertSessionHasErrors('code');
    $this->post($route, workScheduleModulePayload($regular, $half, ['code' => 'ANOTHER-CODE']))->assertSessionHasErrors('name');

    $otherCompany = Company::factory()->create();
    [$otherRegular, $otherHalf] = workScheduleModuleShifts($otherCompany, '2');
    $otherCompany->workSchedules()->create(workScheduleModulePayload($otherRegular, $otherHalf));
    expect($otherCompany->workSchedules()->where('code', 'STANDARD-6-DAY')->exists())->toBeTrue();
});

test('database constraints reject duplicate days and cross company schedule or shift references', function () {
    [, $company] = workScheduleModuleUser('hr_admin');
    [$regular] = workScheduleModuleShifts($company);
    $otherCompany = Company::factory()->create();
    [$foreignShift] = workScheduleModuleShifts($otherCompany, '2');
    $schedule = $company->workSchedules()->create(['name' => 'Raw Schedule', 'code' => 'RAW-SCHEDULE', 'status' => 'active']);

    expect(fn () => $company->workSchedules()->create(['name' => 'Raw Schedule', 'code' => 'OTHER-CODE', 'status' => 'active']))
        ->toThrow(QueryException::class);
    expect(fn () => $company->workSchedules()->create(['name' => 'Other Schedule', 'code' => 'RAW-SCHEDULE', 'status' => 'active']))
        ->toThrow(QueryException::class);
    expect($otherCompany->workSchedules()->create(['name' => 'Raw Schedule', 'code' => 'RAW-SCHEDULE', 'status' => 'active']))->toBeInstanceOf(WorkSchedule::class);

    $schedule->days()->create(['company_id' => $company->id, 'day_of_week' => 1, 'day_type' => 'working', 'shift_id' => $regular->id]);
    expect(fn () => $schedule->days()->create([
        'company_id' => $company->id, 'day_of_week' => 1, 'day_type' => 'working', 'shift_id' => $regular->id,
    ]))->toThrow(QueryException::class);
    expect(fn () => $schedule->days()->create([
        'company_id' => $company->id, 'day_of_week' => 2, 'day_type' => 'working', 'shift_id' => $foreignShift->id,
    ]))->toThrow(QueryException::class);
    expect(fn () => $schedule->days()->create([
        'company_id' => $otherCompany->id, 'day_of_week' => 3, 'day_type' => 'off', 'shift_id' => null,
    ]))->toThrow(QueryException::class);
});

test('schedule status actions are permission checked and soft deletion retains its rows', function () {
    [$owner, $company] = workScheduleModuleUser('company_owner');
    [$regular, $half] = workScheduleModuleShifts($company);
    $this->actingAs($owner)->post(route('companies.work-schedules.store', $company), workScheduleModulePayload($regular, $half))->assertRedirect();
    $schedule = $company->workSchedules()->firstOrFail();

    $this->patch(route('companies.work-schedules.deactivate', [$company, $schedule]))->assertRedirect();
    expect($schedule->fresh()->status->value)->toBe('inactive');
    $this->patch(route('companies.work-schedules.activate', [$company, $schedule]))->assertRedirect();
    expect($schedule->fresh()->status->value)->toBe('active');
    $dayCount = $schedule->days()->count();
    $this->delete(route('companies.work-schedules.destroy', [$company, $schedule]))->assertRedirect();
    expect($schedule->fresh()->trashed())->toBeTrue()
        ->and(WorkSchedule::withTrashed()->findOrFail($schedule->id)->trashed())->toBeTrue()
        ->and($schedule->days()->count())->toBe($dayCount);
    $this->get(route('companies.work-schedules.edit', [$company, $schedule]))->assertNotFound();
});

test('managers can view schedules while HR managers can create and update but not activate or delete', function () {
    [$manager, $managerCompany] = workScheduleModuleUser('manager');
    [$managerRegular, $managerHalf] = workScheduleModuleShifts($managerCompany);
    $managerCompany->workSchedules()->create(workScheduleModulePayload($managerRegular, $managerHalf));
    $this->actingAs($manager)->get(route('companies.work-schedules.index', $managerCompany))->assertOk();
    $this->get(route('companies.work-schedules.create', $managerCompany))->assertForbidden();

    [$hrManager, $company] = workScheduleModuleUser('hr_manager');
    [$regular, $half] = workScheduleModuleShifts($company, '2');
    $this->actingAs($hrManager)->post(route('companies.work-schedules.store', $company), workScheduleModulePayload($regular, $half))->assertRedirect();
    $schedule = $company->workSchedules()->firstOrFail();
    $this->put(route('companies.work-schedules.update', [$company, $schedule]), workScheduleModulePayload($regular, $half, ['name' => 'HR Manager Updated']))->assertRedirect();
    $this->patch(route('companies.work-schedules.deactivate', [$company, $schedule]))->assertForbidden();
    $this->delete(route('companies.work-schedules.destroy', [$company, $schedule]))->assertForbidden();
});

test('employees and accountants are denied and super admins retain selected company access', function () {
    [$employee, $employeeCompany] = workScheduleModuleUser('employee');
    [$accountant, $accountantCompany] = workScheduleModuleUser('accountant');
    $this->actingAs($employee)->get(route('companies.work-schedules.index', $employeeCompany))->assertForbidden();
    $this->actingAs($accountant)->get(route('companies.work-schedules.index', $accountantCompany))->assertForbidden();

    $company = Company::factory()->create();
    app(RolePermissionSeeder::class)->run();
    $superAdmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
    $superAdmin->assignRole(Role::query()->where('team_id', User::SYSTEM_TEAM_ID)->where('name', 'super_admin')->firstOrFail());
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($superAdmin)->get(route('companies.work-schedules.index', $company))->assertOk();
});

test('work schedule list searches filters and paginates within the tenant', function () {
    [$hrAdmin, $company] = workScheduleModuleUser('hr_admin');
    for ($index = 1; $index <= 21; $index++) {
        $schedule = WorkSchedule::factory()->create([
            'company_id' => $company->id,
            'name' => "Pattern {$index}",
            'code' => sprintf('PATTERN-%02d', $index),
            'status' => $index % 2 === 0 ? 'inactive' : 'active',
        ]);
    }

    $this->actingAs($hrAdmin)->get(route('companies.work-schedules.index', ['company' => $company, 'search' => 'PATTERN-01']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('schedules.data', 1)->where('schedules.data.0.code', 'PATTERN-01'));
    $this->get(route('companies.work-schedules.index', ['company' => $company, 'status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->has('schedules.data', 10)->where('schedules.data.0.status', 'inactive'));
    $this->get(route('companies.work-schedules.index', $company))
        ->assertInertia(fn (Assert $page) => $page->has('schedules.data', 20)->where('schedules.total', 21));
});

test('inactive shift used by an existing schedule remains editable and cannot be deleted', function () {
    [$owner, $company] = workScheduleModuleUser('company_owner');
    [$regular, $half] = workScheduleModuleShifts($company);
    $this->actingAs($owner)->post(route('companies.work-schedules.store', $company), workScheduleModulePayload($regular, $half))->assertRedirect();
    $schedule = $company->workSchedules()->firstOrFail();
    $regular->update(['status' => 'inactive']);

    $this->get(route('companies.work-schedules.edit', [$company, $schedule]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('shifts', 2));
    $this->put(route('companies.work-schedules.update', [$company, $schedule]), workScheduleModulePayload($regular, $half, ['name' => 'Updated Existing']))->assertRedirect();
    $this->delete(route('companies.shifts.destroy', [$company, $regular]))->assertForbidden();
});
