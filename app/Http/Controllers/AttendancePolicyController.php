<?php

namespace App\Http\Controllers;

use App\Actions\AttendancePolicies\ChangeAttendancePolicyStatus;
use App\Actions\AttendancePolicies\CreateAttendancePolicy;
use App\Actions\AttendancePolicies\DeleteAttendancePolicy;
use App\Actions\AttendancePolicies\UpdateAttendancePolicy;
use App\Enums\AttendancePolicyStatus;
use App\Http\Requests\AttendancePolicy\StoreAttendancePolicyRequest;
use App\Http\Requests\AttendancePolicy\UpdateAttendancePolicyRequest;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AttendancePolicyController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', AttendancePolicy::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->attendancePolicies()->orderBy('name');
        $search = $request->string('search')->trim()->toString();
        $status = AttendancePolicyStatus::tryFrom($request->string('status')->toString());

        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return Inertia::render('attendance-policies/index', [
            'policies' => $query->paginate(20)->withQueryString()->through(fn (AttendancePolicy $policy): array => $this->policyData($policy)),
            'filters' => ['search' => $search, 'status' => $status?->value ?? ''],
            'company' => $activeCompany->only('id', 'name'),
            'worksitesUrl' => route('companies.attendance-worksites.index', $activeCompany),
            'canCreate' => $request->user()->can('attendance_policy.create'),
            'canUpdate' => $request->user()->can('attendance_policy.update'),
            'canDelete' => $request->user()->can('attendance_policy.delete'),
            'canActivate' => $request->user()->can('attendance_policy.activate'),
            'canDeactivate' => $request->user()->can('attendance_policy.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', AttendancePolicy::class);
        $this->assertSelectedCompany($company);

        return Inertia::render('attendance-policies/form', [
            'policy' => null,
            'company' => $company->only('id', 'name'),
            'worksites' => $company->attendanceWorksites()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'worksitesUrl' => route('companies.attendance-worksites.index', $company),
            'canActivate' => request()->user()->can('attendance_policy.activate'),
            'canDeactivate' => request()->user()->can('attendance_policy.deactivate'),
        ]);
    }

    public function store(StoreAttendancePolicyRequest $request, Company $company, CreateAttendancePolicy $createAttendancePolicy): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $createAttendancePolicy->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.attendance-policies.index', $company)->with('success', 'Attendance policy created.');
    }

    public function edit(Company $company, AttendancePolicy $attendancePolicy): InertiaResponse
    {
        Gate::authorize('update', $attendancePolicy);
        $this->assertSelectedCompany($company);

        return Inertia::render('attendance-policies/form', [
            'policy' => $this->policyData($attendancePolicy, true),
            'company' => $company->only('id', 'name'),
            'worksites' => $company->attendanceWorksites()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'worksitesUrl' => route('companies.attendance-worksites.index', $company),
            'canActivate' => request()->user()->can('attendance_policy.activate'),
            'canDeactivate' => request()->user()->can('attendance_policy.deactivate'),
        ]);
    }

    public function update(UpdateAttendancePolicyRequest $request, Company $company, AttendancePolicy $attendancePolicy, UpdateAttendancePolicy $updateAttendancePolicy): RedirectResponse
    {
        Gate::authorize('update', $attendancePolicy);
        $this->assertSelectedCompany($company);
        $updateAttendancePolicy->handle($attendancePolicy, $request->user(), $request->validated());

        return to_route('companies.attendance-policies.index', $company)->with('success', 'Attendance policy updated.');
    }

    public function activate(Company $company, AttendancePolicy $attendancePolicy, ChangeAttendancePolicyStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('activate', $attendancePolicy);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($attendancePolicy, request()->user(), AttendancePolicyStatus::Active);

        return to_route('companies.attendance-policies.index', $company)->with('success', 'Attendance policy activated.');
    }

    public function deactivate(Company $company, AttendancePolicy $attendancePolicy, ChangeAttendancePolicyStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('deactivate', $attendancePolicy);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($attendancePolicy, request()->user(), AttendancePolicyStatus::Inactive);

        return to_route('companies.attendance-policies.index', $company)->with('success', 'Attendance policy deactivated.');
    }

    public function destroy(Company $company, AttendancePolicy $attendancePolicy, DeleteAttendancePolicy $deleteAttendancePolicy): RedirectResponse
    {
        Gate::authorize('delete', $attendancePolicy);
        $this->assertSelectedCompany($company);
        $deleteAttendancePolicy->handle($attendancePolicy);

        return to_route('companies.attendance-policies.index', $company)->with('success', 'Attendance policy archived.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    /** @return array<string, mixed> */
    private function policyData(AttendancePolicy $attendancePolicy, bool $includeWorksites = false): array
    {
        $data = [
            'id' => $attendancePolicy->id,
            'name' => $attendancePolicy->name,
            'code' => $attendancePolicy->code,
            'description' => $attendancePolicy->description,
            'status' => $attendancePolicy->status->value,
            'grace_in_minutes' => $attendancePolicy->grace_in_minutes,
            'grace_out_minutes' => $attendancePolicy->grace_out_minutes,
            'late_after_minutes' => $attendancePolicy->late_after_minutes,
            'early_leave_after_minutes' => $attendancePolicy->early_leave_after_minutes,
            'half_day_before_minutes' => $attendancePolicy->half_day_before_minutes,
            'absent_before_minutes' => $attendancePolicy->absent_before_minutes,
            'overtime_enabled' => $attendancePolicy->overtime_enabled,
            'overtime_after_minutes' => $attendancePolicy->overtime_after_minutes,
            'updated_at' => $attendancePolicy->updated_at?->toDateTimeString(),
        ];

        if ($includeWorksites) {
            $data['location_mode'] = $attendancePolicy->location_mode->value;
            $data['worksite_ids'] = $attendancePolicy->worksites()
                ->where('attendance_worksites.is_active', true)
                ->pluck('attendance_worksites.id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $data;
    }
}
