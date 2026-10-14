<?php

namespace App\Http\Controllers;

use App\Actions\Holidays\CreateHoliday;
use App\Actions\Holidays\DeleteHoliday;
use App\Actions\Holidays\UpdateHoliday;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Http\Requests\Holiday\StoreHolidayRequest;
use App\Http\Requests\Holiday\UpdateHolidayRequest;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HolidayController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function create(Company $company, HolidayCalendar $holidayCalendar): InertiaResponse
    {
        Gate::authorize('create', [Holiday::class, $holidayCalendar]);
        $activeCompany = $this->assertSelectedCompany($company, $holidayCalendar);

        return Inertia::render('holidays/form', [
            'holiday' => null,
            'calendar' => $holidayCalendar->only('id', 'name', 'year', 'status'),
            'company' => $activeCompany->only('id', 'name'),
            'holidayTypes' => array_map(fn (HolidayType $type): array => ['value' => $type->value, 'label' => str($type->value)->replace('_', ' ')->title()->toString()], HolidayType::cases()),
            'holidayStatuses' => array_map(fn (HolidayStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], HolidayStatus::cases()),
        ]);
    }

    public function store(StoreHolidayRequest $request, Company $company, HolidayCalendar $holidayCalendar, CreateHoliday $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company, $holidayCalendar);
        $create->handle($activeCompany, $holidayCalendar, $request->user(), $request->validated());

        return to_route('companies.holiday-calendars.show', [$company, $holidayCalendar])->with('success', 'Holiday created.');
    }

    public function edit(Company $company, HolidayCalendar $holidayCalendar, Holiday $holiday): InertiaResponse
    {
        Gate::authorize('update', $holiday);
        $activeCompany = $this->assertSelectedCompany($company, $holidayCalendar);

        return Inertia::render('holidays/form', [
            'holiday' => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'description' => $holiday->description,
                'type' => $holiday->type->value,
                'status' => $holiday->status->value,
                'is_optional' => $holiday->is_optional,
            ],
            'calendar' => $holidayCalendar->only('id', 'name', 'year', 'status'),
            'company' => $activeCompany->only('id', 'name'),
            'holidayTypes' => array_map(fn (HolidayType $type): array => ['value' => $type->value, 'label' => str($type->value)->replace('_', ' ')->title()->toString()], HolidayType::cases()),
            'holidayStatuses' => array_map(fn (HolidayStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], HolidayStatus::cases()),
        ]);
    }

    public function update(UpdateHolidayRequest $request, Company $company, HolidayCalendar $holidayCalendar, Holiday $holiday, UpdateHoliday $update): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company, $holidayCalendar);
        $update->handle($activeCompany, $holidayCalendar, $holiday, $request->user(), $request->validated());

        return to_route('companies.holiday-calendars.show', [$company, $holidayCalendar])->with('success', 'Holiday updated.');
    }

    public function destroy(Company $company, HolidayCalendar $holidayCalendar, Holiday $holiday, DeleteHoliday $delete): RedirectResponse
    {
        Gate::authorize('delete', $holiday);
        $this->assertSelectedCompany($company, $holidayCalendar);
        $delete->handle($holiday, request()->user()->id);

        return to_route('companies.holiday-calendars.show', [$company, $holidayCalendar])->with('success', 'Holiday archived.');
    }

    private function assertSelectedCompany(Company $company, HolidayCalendar $calendar): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company) && (int) $calendar->company_id === (int) $company->id, 404);

        return $activeCompany;
    }
}
