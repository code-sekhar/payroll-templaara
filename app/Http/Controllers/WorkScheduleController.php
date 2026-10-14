<?php

namespace App\Http\Controllers;

use App\Actions\WorkSchedules\ChangeWorkScheduleStatus;
use App\Actions\WorkSchedules\CreateWorkSchedule;
use App\Actions\WorkSchedules\DeleteWorkSchedule;
use App\Actions\WorkSchedules\UpdateWorkSchedule;
use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use App\Enums\WorkScheduleStatus;
use App\Http\Requests\WorkSchedule\StoreWorkScheduleRequest;
use App\Http\Requests\WorkSchedule\UpdateWorkScheduleRequest;
use App\Models\Company;
use App\Models\Shift;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class WorkScheduleController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', WorkSchedule::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->workSchedules()->with(['days.shift'])->orderBy('name');
        $search = $request->string('search')->trim()->toString();
        $status = WorkScheduleStatus::tryFrom($request->string('status')->toString());

        if ($search !== '') {
            $query->search($search);
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $schedules = $query->paginate(20)->withQueryString();

        return Inertia::render('work-schedules/index', [
            'schedules' => $schedules->through(fn (WorkSchedule $schedule): array => $this->scheduleSummary($schedule)),
            'filters' => ['search' => $search, 'status' => $status?->value ?? ''],
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $request->user()->can('work_schedule.create'),
            'canUpdate' => $request->user()->can('work_schedule.update'),
            'canDelete' => $request->user()->can('work_schedule.delete'),
            'canActivate' => $request->user()->can('work_schedule.activate'),
            'canDeactivate' => $request->user()->can('work_schedule.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', WorkSchedule::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('work-schedules/form', [
            'schedule' => null,
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($activeCompany),
        ]);
    }

    public function store(StoreWorkScheduleRequest $request, Company $company, CreateWorkSchedule $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.work-schedules.index', $company)->with('success', 'Work schedule created.');
    }

    public function edit(Company $company, WorkSchedule $workSchedule): InertiaResponse
    {
        Gate::authorize('update', $workSchedule);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('work-schedules/form', [
            'schedule' => [
                'id' => $workSchedule->id,
                'name' => $workSchedule->name,
                'code' => $workSchedule->code,
                'description' => $workSchedule->description,
                'status' => $workSchedule->status->value,
                'days' => $workSchedule->days()->with('shift')->get()->map(fn (WorkScheduleDay $day): array => [
                    'day_of_week' => $day->day_of_week->value,
                    'day_type' => $day->day_type->value,
                    'shift_id' => $day->shift_id,
                ])->values(),
            ],
            'company' => $activeCompany->only('id', 'name'),
            ...$this->formOptions($activeCompany, $workSchedule),
        ]);
    }

    public function update(UpdateWorkScheduleRequest $request, Company $company, WorkSchedule $workSchedule, UpdateWorkSchedule $update): RedirectResponse
    {
        Gate::authorize('update', $workSchedule);
        $activeCompany = $this->assertSelectedCompany($company);
        $update->handle($activeCompany, $workSchedule, $request->user(), $request->validated());

        return to_route('companies.work-schedules.index', $company)->with('success', 'Work schedule updated.');
    }

    public function activate(Company $company, WorkSchedule $workSchedule, ChangeWorkScheduleStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('activate', $workSchedule);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($workSchedule, request()->user(), WorkScheduleStatus::Active);

        return to_route('companies.work-schedules.index', $company)->with('success', 'Work schedule activated.');
    }

    public function deactivate(Company $company, WorkSchedule $workSchedule, ChangeWorkScheduleStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('deactivate', $workSchedule);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($workSchedule, request()->user(), WorkScheduleStatus::Inactive);

        return to_route('companies.work-schedules.index', $company)->with('success', 'Work schedule deactivated.');
    }

    public function destroy(Company $company, WorkSchedule $workSchedule, DeleteWorkSchedule $delete): RedirectResponse
    {
        Gate::authorize('delete', $workSchedule);
        $this->assertSelectedCompany($company);
        $delete->handle($workSchedule, request()->user()->id);

        return to_route('companies.work-schedules.index', $company)->with('success', 'Work schedule archived.');
    }

    /** @return array{shifts: list<array{id: int, name: string, code: string, net_duration_minutes: int}>, daysOfWeek: list<array{value: int, label: string}>, dayTypes: list<array{value: string, label: string}>} */
    private function formOptions(Company $company, ?WorkSchedule $schedule = null): array
    {
        $existingShiftIds = $schedule?->days()->whereNotNull('shift_id')->pluck('shift_id')->all() ?? [];
        $shifts = $company->shifts()->where(function (Builder $query) use ($existingShiftIds): void {
            $query->where('status', 'active')->orWhereIn('id', $existingShiftIds);
        })->orderBy('name')->get();

        return [
            'shifts' => $shifts->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'name' => $shift->name,
                'code' => $shift->code,
                'net_duration_minutes' => $shift->net_duration_minutes,
            ])->values()->all(),
            'daysOfWeek' => array_map(fn (DayOfWeek $day): array => ['value' => $day->value, 'label' => $day->label()], DayOfWeek::cases()),
            'dayTypes' => array_map(fn (WorkScheduleDayType $type): array => ['value' => $type->value, 'label' => str($type->value)->replace('_', ' ')->title()->toString()], WorkScheduleDayType::cases()),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduleSummary(WorkSchedule $schedule): array
    {
        $workingDays = $schedule->days->filter(fn (WorkScheduleDay $day): bool => $day->day_type !== WorkScheduleDayType::Off);
        $weeklyMinutes = $workingDays->sum(fn (WorkScheduleDay $day): int => $day->shift?->net_duration_minutes ?? 0);

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'code' => $schedule->code,
            'status' => $schedule->status->value,
            'working_days_count' => $workingDays->count(),
            'weekly_minutes' => $weeklyMinutes,
            'created_at' => $schedule->created_at->toDateString(),
        ];
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
