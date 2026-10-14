<?php

namespace App\Http\Controllers;

use App\Actions\HolidayCalendars\ChangeHolidayCalendarStatus;
use App\Actions\HolidayCalendars\CreateHolidayCalendar;
use App\Actions\HolidayCalendars\DeleteHolidayCalendar;
use App\Actions\HolidayCalendars\UpdateHolidayCalendar;
use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Http\Requests\HolidayCalendar\StoreHolidayCalendarRequest;
use App\Http\Requests\HolidayCalendar\UpdateHolidayCalendarRequest;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HolidayCalendarController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', HolidayCalendar::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->holidayCalendars()->withCount('holidays');
        $search = $request->string('search')->trim()->toString();
        $yearValue = filter_var($request->query('year'), FILTER_VALIDATE_INT);
        $year = is_int($yearValue) && $yearValue >= 1900 && $yearValue <= 2200 ? $yearValue : null;
        $status = HolidayCalendarStatus::tryFrom($request->string('status')->toString());

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }
        if ($year !== null) {
            $query->where('year', $year);
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $calendars = $query->orderByDesc('year')->orderBy('name')->paginate(20)->withQueryString();

        return Inertia::render('holiday-calendars/index', [
            'calendars' => $calendars->through(fn (HolidayCalendar $calendar): array => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'year' => $calendar->year,
                'status' => $calendar->status->value,
                'holiday_count' => $calendar->holidays_count,
                'created_at' => $calendar->created_at->toDateString(),
            ]),
            'filters' => ['search' => $search, 'year' => $year ?? '', 'status' => $status?->value ?? ''],
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $request->user()->can('holiday_calendar.create'),
            'canUpdate' => $request->user()->can('holiday_calendar.update'),
            'canDelete' => $request->user()->can('holiday_calendar.delete'),
            'canActivate' => $request->user()->can('holiday_calendar.activate'),
            'canDeactivate' => $request->user()->can('holiday_calendar.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', HolidayCalendar::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('holiday-calendars/form', [
            'calendar' => null,
            'company' => $activeCompany->only('id', 'name'),
        ]);
    }

    public function store(StoreHolidayCalendarRequest $request, Company $company, CreateHolidayCalendar $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $calendar = $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.holiday-calendars.show', [$company, $calendar])->with('success', 'Holiday calendar created.');
    }

    public function show(Request $request, Company $company, HolidayCalendar $holidayCalendar): InertiaResponse
    {
        Gate::authorize('view', $holidayCalendar);
        $activeCompany = $this->assertSelectedCompany($company);
        $holidaysQuery = $holidayCalendar->holidays();
        $search = $request->string('search')->trim()->toString();
        $status = HolidayStatus::tryFrom($request->string('status')->toString());
        $type = HolidayType::tryFrom($request->string('type')->toString());
        $optional = in_array($request->query('optional'), ['0', '1'], true) ? $request->query('optional') === '1' : null;

        if ($search !== '') {
            $holidaysQuery->where('name', 'like', '%'.$search.'%');
        }
        if ($status !== null) {
            $holidaysQuery->where('status', $status->value);
        }
        if ($type !== null) {
            $holidaysQuery->where('type', $type->value);
        }
        if ($optional !== null) {
            $holidaysQuery->where('is_optional', $optional);
        }

        $holidays = $holidaysQuery->with('creator')->orderBy('date')->paginate(25)->withQueryString();

        return Inertia::render('holiday-calendars/show', [
            'calendar' => [
                'id' => $holidayCalendar->id,
                'name' => $holidayCalendar->name,
                'year' => $holidayCalendar->year,
                'description' => $holidayCalendar->description,
                'status' => $holidayCalendar->status->value,
                'created_at' => $holidayCalendar->created_at->toDateString(),
            ],
            'holidays' => $holidays->through(fn (Holiday $holiday): array => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'description' => $holiday->description,
                'type' => $holiday->type->value,
                'status' => $holiday->status->value,
                'is_optional' => $holiday->is_optional,
                'created_by' => $holiday->creator?->name,
            ]),
            'filters' => ['search' => $search, 'status' => $status?->value ?? '', 'type' => $type?->value ?? '', 'optional' => $optional === null ? '' : (string) (int) $optional],
            'company' => $activeCompany->only('id', 'name'),
            'canUpdateCalendar' => $request->user()->can('holiday_calendar.update', $holidayCalendar),
            'canDeleteCalendar' => $request->user()->can('holiday_calendar.delete', $holidayCalendar),
            'canActivateCalendar' => $request->user()->can('activate', $holidayCalendar),
            'canDeactivateCalendar' => $request->user()->can('deactivate', $holidayCalendar),
            'canCreateHoliday' => $request->user()->can('create', [Holiday::class, $holidayCalendar]),
            'canUpdateHoliday' => $request->user()->can('holiday.update'),
            'canDeleteHoliday' => $request->user()->can('holiday.delete'),
            'holidayTypes' => array_map(fn (HolidayType $holidayType): array => ['value' => $holidayType->value, 'label' => str($holidayType->value)->replace('_', ' ')->title()->toString()], HolidayType::cases()),
            'holidayStatuses' => array_map(fn (HolidayStatus $holidayStatus): array => ['value' => $holidayStatus->value, 'label' => ucfirst($holidayStatus->value)], HolidayStatus::cases()),
        ]);
    }

    public function edit(Company $company, HolidayCalendar $holidayCalendar): InertiaResponse
    {
        Gate::authorize('update', $holidayCalendar);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('holiday-calendars/form', [
            'calendar' => [
                'id' => $holidayCalendar->id,
                'name' => $holidayCalendar->name,
                'year' => $holidayCalendar->year,
                'description' => $holidayCalendar->description,
                'status' => $holidayCalendar->status->value,
            ],
            'company' => $activeCompany->only('id', 'name'),
        ]);
    }

    public function update(UpdateHolidayCalendarRequest $request, Company $company, HolidayCalendar $holidayCalendar, UpdateHolidayCalendar $update): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $holidayCalendar, $request->user(), $request->validated());

        return to_route('companies.holiday-calendars.show', [$company, $holidayCalendar])->with('success', 'Holiday calendar updated.');
    }

    public function activate(Company $company, HolidayCalendar $holidayCalendar, ChangeHolidayCalendarStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('activate', $holidayCalendar);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($holidayCalendar, request()->user(), HolidayCalendarStatus::Active);

        return back()->with('success', 'Holiday calendar activated.');
    }

    public function deactivate(Company $company, HolidayCalendar $holidayCalendar, ChangeHolidayCalendarStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('deactivate', $holidayCalendar);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($holidayCalendar, request()->user(), HolidayCalendarStatus::Inactive);

        return back()->with('success', 'Holiday calendar deactivated.');
    }

    public function destroy(Company $company, HolidayCalendar $holidayCalendar, DeleteHolidayCalendar $delete): RedirectResponse
    {
        Gate::authorize('delete', $holidayCalendar);
        $this->assertSelectedCompany($company);
        $delete->handle($holidayCalendar, request()->user()->id);

        return to_route('companies.holiday-calendars.index', $company)->with('success', 'Holiday calendar archived. Its holiday history was retained.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
