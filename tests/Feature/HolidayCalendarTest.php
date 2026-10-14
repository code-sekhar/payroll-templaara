<?php

use App\Actions\SeedCompanyRoles;
use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/** @return array{User, Company} */
function holidayCalendarModuleUser(string $role): array
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

/** @param array<string, mixed> $overrides */
function holidayCalendarPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Company Holidays',
        'year' => 2026,
        'description' => 'Company holiday calendar',
    ], $overrides);
}

function holidayCalendarRecord(Company $company, array $overrides = []): HolidayCalendar
{
    return $company->holidayCalendars()->create(array_merge([
        'name' => 'Company Holidays',
        'year' => 2026,
        'description' => null,
        'status' => HolidayCalendarStatus::Active,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function holidayPayload(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-10-21',
        'name' => 'Autumn Festival',
        'description' => 'Office holiday',
        'type' => 'festival',
        'status' => 'active',
        'is_optional' => false,
    ], $overrides);
}

function holidayRecord(Company $company, HolidayCalendar $calendar, array $overrides = []): Holiday
{
    return $calendar->holidays()->create(array_merge([
        'company_id' => $company->id,
        'date' => '2026-10-21',
        'name' => 'Autumn Festival',
        'type' => 'festival',
        'status' => 'active',
        'is_optional' => false,
    ], $overrides));
}

test('an authorized HR user creates a company calendar with creator attribution', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');

    $this->actingAs($hr)->post(route('companies.holiday-calendars.store', $company), holidayCalendarPayload())
        ->assertRedirect();

    $calendar = $company->holidayCalendars()->firstOrFail();
    expect($calendar->name)->toBe('Company Holidays')
        ->and($calendar->year)->toBe(2026)
        ->and($calendar->status)->toBe(HolidayCalendarStatus::Active)
        ->and($calendar->creator->is($hr))->toBeTrue()
        ->and($calendar->updater->is($hr))->toBeTrue();
});

test('calendar CRUD, activation, deactivation, and soft deletion preserve holiday history', function () {
    [$owner, $company] = holidayCalendarModuleUser('company_owner');
    $calendar = holidayCalendarRecord($company);
    $holiday = holidayRecord($company, $calendar);
    $this->actingAs($owner);

    $this->put(route('companies.holiday-calendars.update', [$company, $calendar]), holidayCalendarPayload(['year' => 2027]))
        ->assertSessionHasErrors('year');
    $this->put(route('companies.holiday-calendars.update', [$company, $calendar]), holidayCalendarPayload(['name' => 'Updated Holidays']))
        ->assertRedirect();
    expect($calendar->fresh()->name)->toBe('Updated Holidays')
        ->and($calendar->fresh()->updated_by)->toBe($owner->id);

    $this->patch(route('companies.holiday-calendars.deactivate', [$company, $calendar]))->assertRedirect();
    expect($calendar->fresh()->status)->toBe(HolidayCalendarStatus::Inactive);
    $this->patch(route('companies.holiday-calendars.activate', [$company, $calendar]))->assertRedirect();
    expect($calendar->fresh()->status)->toBe(HolidayCalendarStatus::Active);

    $this->delete(route('companies.holiday-calendars.destroy', [$company, $calendar]))->assertRedirect();
    expect(HolidayCalendar::withTrashed()->findOrFail($calendar->id)->trashed())->toBeTrue()
        ->and(Holiday::withTrashed()->findOrFail($holiday->id)->trashed())->toBeFalse()
        ->and(Holiday::query()->whereKey($holiday->id)->exists())->toBeTrue();
});

test('calendar name and year are unique per company and allow the same calendar in another tenant', function () {
    [$owner, $company] = holidayCalendarModuleUser('company_owner');
    $otherCompany = Company::factory()->create();
    $calendar = holidayCalendarRecord($company);
    $this->actingAs($owner);

    $this->post(route('companies.holiday-calendars.store', $company), holidayCalendarPayload())
        ->assertSessionHasErrors('name');
    $this->post(route('companies.holiday-calendars.store', $company), holidayCalendarPayload(['year' => 2027]))
        ->assertRedirect();

    $otherCalendar = holidayCalendarRecord($otherCompany);
    expect($otherCalendar->name)->toBe($calendar->name)
        ->and($otherCalendar->year)->toBe($calendar->year);
});

test('calendar year validation accepts future planning years and rejects invalid ranges or forged tenant fields', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $this->actingAs($hr);

    $this->post(route('companies.holiday-calendars.store', $company), holidayCalendarPayload(['name' => 'Future Plan', 'year' => 2035]))
        ->assertRedirect();
    $this->post(route('companies.holiday-calendars.store', $company), holidayCalendarPayload(['name' => 'Invalid Plan', 'year' => 2201]))
        ->assertSessionHasErrors('year');
    $this->post(route('companies.holiday-calendars.store', $company), holidayCalendarPayload(['name' => 'Forged Plan', 'company_id' => 999]))
        ->assertSessionHasErrors('company_id');
});

test('holiday creation and update enforce the calendar year and record audit users', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $calendar = holidayCalendarRecord($company);
    $baseRoute = ['company' => $company, 'holidayCalendar' => $calendar];
    $this->actingAs($hr);

    $this->post(route('companies.holiday-calendars.holidays.store', $baseRoute), holidayPayload())
        ->assertRedirect(route('companies.holiday-calendars.show', [$company, $calendar]));
    $holiday = $calendar->holidays()->firstOrFail();
    expect($holiday->company_id)->toBe($company->id)
        ->and($holiday->holiday_calendar_id)->toBe($calendar->id)
        ->and($holiday->date->toDateString())->toBe('2026-10-21')
        ->and($holiday->type->value)->toBe('festival')
        ->and($holiday->creator->is($hr))->toBeTrue();

    $this->put(route('companies.holiday-calendars.holidays.update', [...$baseRoute, 'holiday' => $holiday]), holidayPayload(['name' => 'Renamed Festival']))
        ->assertRedirect();
    $this->put(route('companies.holiday-calendars.holidays.update', [...$baseRoute, 'holiday' => $holiday]), holidayPayload(['name' => 'Updated Festival', 'status' => 'inactive', 'is_optional' => true]))
        ->assertRedirect();
    expect($holiday->fresh()->name)->toBe('Updated Festival')
        ->and($holiday->fresh()->status)->toBe(HolidayStatus::Inactive)
        ->and($holiday->fresh()->is_optional)->toBeTrue()
        ->and($holiday->fresh()->updated_by)->toBe($hr->id);
});

test('holiday date type status and optional values are validated and duplicate active dates are rejected', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $calendar = holidayCalendarRecord($company);
    $baseRoute = ['company' => $company, 'holidayCalendar' => $calendar];
    $url = route('companies.holiday-calendars.holidays.store', $baseRoute);
    $this->actingAs($hr);

    $this->post($url, holidayPayload(['date' => '2027-01-01']))->assertSessionHasErrors('date');
    $this->post($url, holidayPayload(['date' => '2026-02-30']))->assertSessionHasErrors('date');
    $this->post($url, holidayPayload(['type' => 'unknown']))->assertSessionHasErrors('type');
    $this->post($url, holidayPayload(['status' => 'deleted']))->assertSessionHasErrors('status');
    $this->post($url, holidayPayload(['is_optional' => 'maybe']))->assertSessionHasErrors('is_optional');
    $this->post($url, holidayPayload())->assertRedirect();
    $this->post($url, holidayPayload(['name' => 'Another Holiday']))->assertSessionHasErrors('date');

    $inactive = holidayRecord($company, $calendar, ['status' => HolidayStatus::Inactive, 'name' => 'Inactive Historical Holiday']);
    $this->post($url, holidayPayload(['name' => 'Replacement Holiday']))->assertSessionHasErrors('date');
    expect($inactive->fresh())->not->toBeNull();
});

test('inactive and soft deleted holidays release their date while duplicate active date constraint stays in the database', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $calendar = holidayCalendarRecord($company);
    $existing = holidayRecord($company, $calendar, ['status' => HolidayStatus::Inactive]);

    $this->actingAs($hr)->post(route('companies.holiday-calendars.holidays.store', [$company, $calendar]), holidayPayload())
        ->assertRedirect();
    $activeHoliday = $calendar->holidays()->where('status', 'active')->firstOrFail();
    expect(fn () => holidayRecord($company, $calendar, ['name' => 'Duplicate Active']))
        ->toThrow(QueryException::class);

    $this->delete(route('companies.holiday-calendars.holidays.destroy', [$company, $calendar, $activeHoliday]))->assertRedirect();
    $replacement = holidayRecord($company, $calendar, ['name' => 'Replacement Holiday']);
    expect(Holiday::withTrashed()->findOrFail($activeHoliday->id)->trashed())->toBeTrue()
        ->and($replacement->date->toDateString())->toBe($existing->date->toDateString());
});

test('inactive calendars retain holiday history but reject new holiday creation', function () {
    [$owner, $company] = holidayCalendarModuleUser('company_owner');
    $calendar = holidayCalendarRecord($company, ['status' => HolidayCalendarStatus::Inactive]);
    $holiday = holidayRecord($company, $calendar);

    $this->actingAs($owner)->get(route('companies.holiday-calendars.show', [$company, $calendar]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('holiday-calendars/show', false)
            ->where('calendar.status', 'inactive')
            ->where('holidays.data.0.id', $holiday->id)
            ->where('canCreateHoliday', false));
    $this->get(route('companies.holiday-calendars.holidays.create', [$company, $calendar]))->assertForbidden();
    $this->post(route('companies.holiday-calendars.holidays.store', [$company, $calendar]), holidayPayload())->assertForbidden();
});

test('nested routes block another company calendar and forged calendar ownership', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $otherCompany = Company::factory()->create();
    $otherCalendar = holidayCalendarRecord($otherCompany);
    $foreignHoliday = holidayRecord($otherCompany, $otherCalendar);
    $this->actingAs($hr);

    $this->get(route('companies.holiday-calendars.show', [$company, $otherCalendar]))->assertNotFound();
    $this->get(route('companies.holiday-calendars.edit', [$company, $otherCalendar]))->assertNotFound();
    $this->put(route('companies.holiday-calendars.update', [$company, $otherCalendar]), holidayCalendarPayload())->assertNotFound();
    $this->delete(route('companies.holiday-calendars.destroy', [$company, $otherCalendar]))->assertNotFound();
    $this->get(route('companies.holiday-calendars.holidays.edit', [$company, $otherCalendar, $foreignHoliday]))->assertNotFound();
    $this->put(route('companies.holiday-calendars.holidays.update', [$company, $otherCalendar, $foreignHoliday]), holidayPayload())->assertNotFound();
    $this->delete(route('companies.holiday-calendars.holidays.destroy', [$company, $otherCalendar, $foreignHoliday]))->assertNotFound();
    $calendar = holidayCalendarRecord($company, ['name' => 'Own Calendar']);
    $this->post(route('companies.holiday-calendars.holidays.store', [$company, $calendar]), holidayPayload(['holiday_calendar_id' => $otherCalendar->id]))
        ->assertSessionHasErrors('holiday_calendar_id');

    expect($foreignHoliday->fresh())->not->toBeNull();
});

test('calendar and holiday indexes filter and paginate with dates sorted ascending', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $calendar = holidayCalendarRecord($company);
    holidayRecord($company, $calendar, ['date' => '2026-12-25', 'name' => 'Christmas']);
    holidayRecord($company, $calendar, ['date' => '2026-01-26', 'name' => 'Republic Day']);
    holidayRecord($company, $calendar, ['date' => '2026-08-15', 'name' => 'Independence Day']);
    foreach (range(1, 21) as $index) {
        holidayCalendarRecord($company, ['name' => 'Archive '.$index, 'year' => 2025]);
    }

    $this->actingAs($hr)->get(route('companies.holiday-calendars.index', [$company, 'year' => 2026, 'status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('holiday-calendars/index', false)
            ->where('calendars.total', 1)
            ->where('calendars.data.0.holiday_count', 3));
    $this->get(route('companies.holiday-calendars.show', [$company, $calendar, 'search' => 'day']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('holiday-calendars/show', false)
            ->has('holidays.data', 2)
            ->where('holidays.data.0.date', '2026-01-26'));
    $this->get(route('companies.holiday-calendars.index', [$company, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('calendars.data', 2));
});

test('role permissions distinguish view-only managers from HR managers and employees', function () {
    [$manager, $managerCompany] = holidayCalendarModuleUser('manager');
    $managerCalendar = holidayCalendarRecord($managerCompany);
    $this->actingAs($manager)->get(route('companies.holiday-calendars.index', $managerCompany))->assertOk();
    $this->get(route('companies.holiday-calendars.create', $managerCompany))->assertForbidden();
    $this->post(route('companies.holiday-calendars.store', $managerCompany), holidayCalendarPayload())->assertForbidden();
    $this->post(route('companies.holiday-calendars.holidays.store', [$managerCompany, $managerCalendar]), holidayPayload())->assertForbidden();

    [$hrManager, $hrManagerCompany] = holidayCalendarModuleUser('hr_manager');
    $hrManagerCalendar = holidayCalendarRecord($hrManagerCompany);
    $this->actingAs($hrManager)->post(route('companies.holiday-calendars.store', $hrManagerCompany), holidayCalendarPayload(['name' => 'HR Manager Calendar']))->assertRedirect();
    $this->post(route('companies.holiday-calendars.holidays.store', [$hrManagerCompany, $hrManagerCalendar]), holidayPayload())->assertRedirect();
    $this->delete(route('companies.holiday-calendars.destroy', [$hrManagerCompany, $hrManagerCalendar]))->assertForbidden();

    [$employee, $employeeCompany] = holidayCalendarModuleUser('employee');
    $this->actingAs($employee)->get(route('companies.holiday-calendars.index', $employeeCompany))->assertForbidden();
});

test('database rejects a holiday whose company differs from its calendar', function () {
    [, $company] = holidayCalendarModuleUser('hr_admin');
    $otherCompany = Company::factory()->create();
    $calendar = holidayCalendarRecord($otherCompany);

    expect(fn () => Holiday::unguarded(fn (): Holiday => Holiday::query()->create([
        'company_id' => $company->id,
        'holiday_calendar_id' => $calendar->id,
        'date' => '2026-10-21',
        'name' => 'Invalid Tenant Holiday',
        'type' => 'public',
        'status' => 'active',
        'is_optional' => false,
    ])))->toThrow(QueryException::class);
});

test('calendar list counts holidays without hydrating holiday rows', function () {
    [$hr, $company] = holidayCalendarModuleUser('hr_admin');
    $calendar = holidayCalendarRecord($company);
    foreach (range(1, 3) as $index) {
        holidayRecord($company, $calendar, ['date' => sprintf('2026-12-%02d', $index), 'name' => 'Holiday '.$index]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($hr)->get(route('companies.holiday-calendars.index', $company))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(collect($queries)->pluck('query')->filter(fn (string $query): bool => str_contains(strtolower($query), 'from "holidays"') && ! str_contains(strtolower($query), 'count(')))->toBeEmpty();
});
