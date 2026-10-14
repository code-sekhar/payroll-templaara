<?php

namespace App\Http\Controllers;

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\UpdateShift;
use App\Enums\ShiftStatus;
use App\Http\Requests\Shift\StoreShiftRequest;
use App\Http\Requests\Shift\UpdateShiftRequest;
use App\Models\Company;
use App\Models\Shift;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ShiftController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', Shift::class);
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        $query = $activeCompany->shifts()->orderBy('start_time')->orderBy('name');
        $search = $request->string('search')->trim()->toString();
        $status = ShiftStatus::tryFrom($request->string('status')->toString());

        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return Inertia::render('shifts/index', [
            'shifts' => $query->paginate(20)->withQueryString()->through(fn (Shift $shift): array => $this->shiftData($shift)),
            'filters' => ['search' => $search, 'status' => $status?->value ?? ''],
            'company' => $activeCompany->only('id', 'name', 'timezone'),
            'canCreate' => $request->user()->can('shift.create'),
            'canUpdate' => $request->user()->can('shift.update'),
            'canDelete' => $request->user()->can('shift.delete'),
            'canActivate' => $request->user()->can('shift.activate'),
            'canDeactivate' => $request->user()->can('shift.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', Shift::class);
        $this->assertSelectedCompany($company);

        return Inertia::render('shifts/form', [
            'shift' => null,
            'company' => $company->only('id', 'name', 'timezone'),
            'canActivate' => request()->user()->can('shift.activate'),
            'canDeactivate' => request()->user()->can('shift.deactivate'),
        ]);
    }

    public function store(StoreShiftRequest $request, Company $company, CreateShift $createShift): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $createShift->handle($activeCompany, $request->validated());

        return to_route('companies.shifts.index', $company)->with('success', 'Shift created.');
    }

    public function edit(Company $company, Shift $shift): InertiaResponse
    {
        Gate::authorize('update', $shift);
        $this->assertSelectedCompany($company);

        return Inertia::render('shifts/form', [
            'shift' => $this->shiftData($shift),
            'company' => $company->only('id', 'name', 'timezone'),
            'canActivate' => request()->user()->can('shift.activate'),
            'canDeactivate' => request()->user()->can('shift.deactivate'),
        ]);
    }

    public function update(UpdateShiftRequest $request, Company $company, Shift $shift, UpdateShift $updateShift): RedirectResponse
    {
        Gate::authorize('update', $shift);
        $this->assertSelectedCompany($company);
        $updateShift->handle($shift, $request->validated());

        return to_route('companies.shifts.index', $company)->with('success', 'Shift updated.');
    }

    public function activate(Company $company, Shift $shift): RedirectResponse
    {
        Gate::authorize('activate', $shift);
        $this->assertSelectedCompany($company);
        $shift->update(['status' => ShiftStatus::Active]);

        return to_route('companies.shifts.index', $company)->with('success', 'Shift activated.');
    }

    public function deactivate(Company $company, Shift $shift): RedirectResponse
    {
        Gate::authorize('deactivate', $shift);
        $this->assertSelectedCompany($company);
        $shift->update(['status' => ShiftStatus::Inactive]);

        return to_route('companies.shifts.index', $company)->with('success', 'Shift deactivated.');
    }

    public function destroy(Company $company, Shift $shift): RedirectResponse
    {
        Gate::authorize('delete', $shift);
        $this->assertSelectedCompany($company);
        $shift->delete();

        return to_route('companies.shifts.index', $company)->with('success', 'Shift deleted.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    /** @return array<string, mixed> */
    private function shiftData(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'code' => $shift->code,
            'start_time' => substr($shift->start_time, 0, 5),
            'end_time' => substr($shift->end_time, 0, 5),
            'break_duration_minutes' => $shift->break_duration_minutes,
            'grace_period_minutes' => $shift->grace_period_minutes,
            'is_overnight' => $shift->is_overnight,
            'duration_minutes' => $shift->duration_minutes,
            'net_duration_minutes' => $shift->net_duration_minutes,
            'status' => $shift->status->value,
            'description' => $shift->description,
        ];
    }
}
