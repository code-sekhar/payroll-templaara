<?php

namespace App\Http\Controllers;

use App\Actions\AttendanceWorksites\ChangeAttendanceWorksiteStatus;
use App\Http\Requests\AttendanceWorksite\StoreAttendanceWorksiteRequest;
use App\Http\Requests\AttendanceWorksite\UpdateAttendanceWorksiteRequest;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AttendanceWorksiteController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', AttendanceWorksite::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('attendance-worksites/index', [
            'worksites' => $activeCompany->attendanceWorksites()->orderBy('name')->get()->map(fn (AttendanceWorksite $worksite): array => $this->worksiteData($worksite)),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => request()->user()->can('attendance_worksite.create'),
            'canUpdate' => request()->user()->can('attendance_worksite.update'),
            'canActivate' => request()->user()->can('attendance_worksite.activate'),
            'canDeactivate' => request()->user()->can('attendance_worksite.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', AttendanceWorksite::class);
        $this->assertSelectedCompany($company);

        return Inertia::render('attendance-worksites/form', ['worksite' => null, 'company' => $company->only('id', 'name')]);
    }

    public function store(StoreAttendanceWorksiteRequest $request, Company $company): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $activeCompany->attendanceWorksites()->create($request->validated());

        return to_route('companies.attendance-worksites.index', $company)->with('success', 'Worksite created.');
    }

    public function edit(Company $company, AttendanceWorksite $attendanceWorksite): InertiaResponse
    {
        Gate::authorize('update', $attendanceWorksite);
        $this->assertSelectedCompany($company);

        return Inertia::render('attendance-worksites/form', [
            'worksite' => $this->worksiteData($attendanceWorksite),
            'company' => $company->only('id', 'name'),
        ]);
    }

    public function update(UpdateAttendanceWorksiteRequest $request, Company $company, AttendanceWorksite $attendanceWorksite): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        DB::transaction(function () use ($request, $attendanceWorksite): void {
            $worksite = AttendanceWorksite::query()->where('company_id', $attendanceWorksite->company_id)
                ->whereKey($attendanceWorksite->getKey())->lockForUpdate()->firstOrFail();
            $worksite->update($request->validated());
        });

        return to_route('companies.attendance-worksites.index', $company)->with('success', 'Worksite updated.');
    }

    public function activate(Company $company, AttendanceWorksite $attendanceWorksite, ChangeAttendanceWorksiteStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('activate', $attendanceWorksite);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($attendanceWorksite, true);

        return to_route('companies.attendance-worksites.index', $company)->with('success', 'Worksite activated.');
    }

    public function deactivate(Company $company, AttendanceWorksite $attendanceWorksite, ChangeAttendanceWorksiteStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('deactivate', $attendanceWorksite);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($attendanceWorksite, false);

        return to_route('companies.attendance-worksites.index', $company)->with('success', 'Worksite deactivated.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    /** @return array<string, mixed> */
    private function worksiteData(AttendanceWorksite $worksite): array
    {
        return [
            'id' => $worksite->id,
            'name' => $worksite->name,
            'latitude' => $worksite->latitude,
            'longitude' => $worksite->longitude,
            'radius_meters' => $worksite->radius_meters,
            'is_active' => $worksite->is_active,
        ];
    }
}
