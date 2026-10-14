<?php

namespace App\Http\Controllers;

use App\Actions\AttendancePunches\CreateManualAttendancePunch;
use App\Actions\AttendancePunches\CreateWebAttendancePunch;
use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Http\Requests\AttendancePunch\IndexAttendancePunchRequest;
use App\Http\Requests\AttendancePunch\StoreManualAttendancePunchRequest;
use App\Http\Requests\AttendancePunch\StoreWebAttendancePunchRequest;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AttendancePunchController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(IndexAttendancePunchRequest $request, Company $company): InertiaResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $filters = $request->validated();
        $user = $request->user();
        $query = $activeCompany->attendancePunches()
            ->with(['employee', 'createdBy'])
            ->orderByDesc('punched_at')->orderByDesc('id');

        if (! $user->can('employee.view')) {
            $this->scopeToManagerEmployees($query, $user, $activeCompany);
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->whereHas('employee', fn (Builder $employeeQuery): Builder => $employeeQuery
                ->where('employee_code', 'like', $search)
                ->orWhere('first_name', 'like', $search)
                ->orWhere('middle_name', 'like', $search)
                ->orWhere('last_name', 'like', $search));
        }

        if (filled($filters['punch_type'] ?? null)) {
            $query->where('punch_type', $filters['punch_type']);
        }

        if (filled($filters['source'] ?? null)) {
            $query->where('source', $filters['source']);
        }

        $timezone = $activeCompany->timezone ?: config('app.timezone', 'UTC');
        if (filled($filters['date_from'] ?? null)) {
            $query->where('punched_at', '>=', CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay()->utc());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('punched_at', '<', CarbonImmutable::parse($filters['date_to'], $timezone)->addDay()->startOfDay()->utc());
        }

        return Inertia::render('attendance-punches/index', [
            'punches' => $query->paginate(25)->withQueryString()->through(fn (AttendancePunch $punch): array => [
                'id' => $punch->id,
                'employee' => $punch->employee?->full_name ?? 'Archived employee',
                'employee_code' => $punch->employee?->employee_code ?? '—',
                'punch_type' => $punch->punch_type->value,
                'punched_at_local' => $punch->punched_at->setTimezone($punch->timezone)->format('d M Y, h:i A'),
                'timezone' => $punch->timezone,
                'source' => $punch->source->value,
                'has_location' => $punch->latitude !== null || $punch->longitude !== null,
                'location_result' => $punch->location_result,
                'worksite_name' => $punch->worksite_name_snapshot,
                'coordinates' => $punch->latitude !== null && $punch->longitude !== null ? $punch->latitude.', '.$punch->longitude : null,
                'distance_meters' => $punch->location_distance_meters,
                'radius_meters' => $punch->worksite_radius_meters_snapshot,
                'device_identifier' => $punch->device_identifier,
                'external_reference' => $punch->external_reference,
                'created_by' => $punch->createdBy?->name ?? '—',
            ]),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'punch_type' => $filters['punch_type'] ?? '',
                'source' => $filters['source'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
            'punchTypes' => $this->enumOptions(AttendancePunchType::cases()),
            'sources' => $this->enumOptions(AttendancePunchSource::cases()),
            'company' => $activeCompany->only('id', 'name', 'timezone'),
            'canCreateManual' => $user->can('attendance_punch.create_manual'),
        ]);
    }

    public function createManual(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('createManual', [AttendancePunch::class, $company]);
        $activeCompany = $this->assertSelectedCompany($company);
        $search = $request->string('employee_search')->trim()->toString();
        $employees = $this->visibleEmployees($request->user(), $activeCompany, $search)
            ->orderBy('last_name')->orderBy('first_name')->limit(50)
            ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->full_name,
            ])->values();

        return Inertia::render('attendance-punches/manual-create', [
            'employees' => $employees,
            'employeeSearch' => $search,
            'company' => $activeCompany->only('id', 'name', 'timezone'),
        ]);
    }

    public function storeManual(StoreManualAttendancePunchRequest $request, Company $company, CreateManualAttendancePunch $createPunch): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $validated = $request->validated();
        $employee = $activeCompany->employees()->findOrFail($validated['employee_id']);

        $createPunch->handle(
            $activeCompany,
            $employee,
            $request->user(),
            $validated,
            $request->ip(),
            $request->userAgent(),
        );

        return to_route('companies.attendance-punches.index', $activeCompany)->with('success', 'Manual attendance punch recorded.');
    }

    public function selfPunch(StoreWebAttendancePunchRequest $request, Company $company, CreateWebAttendancePunch $createPunch): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $employee = $request->attributes->get('employee_portal.employee');

        abort_unless($employee instanceof Employee && (int) $employee->company_id === (int) $activeCompany->getKey(), 403);
        Gate::authorize('selfPunch', [AttendancePunch::class, $activeCompany, $employee]);

        $createPunch->handle(
            $activeCompany,
            $employee,
            $request->user(),
            AttendancePunchType::from($request->validated('punch_type')),
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('success', 'Attendance punch recorded.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    private function scopeToManagerEmployees(Builder|Relation $query, User $user, Company $company): void
    {
        $managerEmployeeIds = $user->employees()->where('company_id', $company->id)->select('id');

        $query->whereHas('employee', fn (Builder $employeeQuery): Builder => $employeeQuery
            ->where(fn (Builder $visible): Builder => $visible
                ->whereIn('id', $managerEmployeeIds)
                ->orWhereIn('manager_id', $managerEmployeeIds)));
    }

    private function visibleEmployees(User $user, Company $company, string $search): Builder
    {
        $query = $company->employees();

        if (! $user->can('employee.view')) {
            $managerEmployeeIds = $user->employees()->where('company_id', $company->id)->select('id');
            $query->where(fn (Builder $visible): Builder => $visible
                ->whereIn('id', $managerEmployeeIds)
                ->orWhereIn('manager_id', $managerEmployeeIds));
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $builder): Builder => $builder
                ->where('employee_code', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like));
        }

        return $query;
    }

    /** @param array<\BackedEnum> $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => [
            'value' => $case->value,
            'label' => str($case->value)->replace('_', ' ')->title()->toString(),
        ], $cases);
    }
}
