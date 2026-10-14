<?php

namespace App\Http\Controllers;

use App\Actions\AttendanceRegularizations\ApproveAttendanceRegularizationRequest as ApproveRegularization;
use App\Actions\AttendanceRegularizations\CancelAttendanceRegularizationRequest;
use App\Actions\AttendanceRegularizations\CreateAttendanceRegularizationRequest as CreateRegularization;
use App\Actions\AttendanceRegularizations\RejectAttendanceRegularizationRequest as RejectRegularization;
use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationStatus;
use App\Enums\AttendanceRegularizationType;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Http\Requests\AttendanceRegularization\ApproveAttendanceRegularizationFormRequest;
use App\Http\Requests\AttendanceRegularization\IndexAttendanceRegularizationRequest;
use App\Http\Requests\AttendanceRegularization\RejectAttendanceRegularizationFormRequest;
use App\Http\Requests\AttendanceRegularization\StoreAttendanceRegularizationRequest;
use App\Http\Requests\AttendanceRegularization\StoreSelfAttendanceRegularizationRequest;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendancePunchWindow;
use App\Services\Attendance\AttendanceRegularizationHistoryContextQuery;
use App\Services\Attendance\AttendanceRegularizationNotificationService;
use App\Services\WorkSchedules\WorkScheduleResolver;
use App\Tenancy\ActiveCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AttendanceRegularizationController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(IndexAttendanceRegularizationRequest $request, Company $company): InertiaResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $filters = $request->validated();
        $user = $request->user();
        if (! $request->query->has('status') && $user->can('attendance_regularization.view')) {
            $filters['status'] = AttendanceRegularizationStatus::Pending->value;
        } elseif ($request->query->has('status') && blank($filters['status'] ?? null)) {
            $filters['status'] = '';
        }
        $query = $activeCompany->attendanceRegularizationRequests()
            ->with(['employee', 'requestedBy', 'items'])
            ->orderByDesc('requested_at')->orderByDesc('id');

        $this->scopeVisibleEmployees($query, $user, $activeCompany);

        if (filled($filters['search'] ?? null)) {
            $like = '%'.$filters['search'].'%';
            $query->whereHas('employee', fn (Builder $builder): Builder => $builder
                ->where('employee_code', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like));
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if (filled($filters[$filter] ?? null)) {
                $query->whereDate('attendance_date', $operator, $filters[$filter]);
            }
        }
        foreach (['request_type', 'status'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (filled($filters['requester'] ?? null)) {
            $query->whereHas('requestedBy', fn (Builder $builder): Builder => $builder->where('name', 'like', '%'.$filters['requester'].'%'));
        }

        return Inertia::render('attendance-regularizations/index', [
            'requests' => $query->paginate(25)->withQueryString()->through(fn (AttendanceRegularizationRequest $item): array => [
                'id' => $item->id,
                'employee' => $item->employee?->full_name ?? 'Archived employee',
                'employee_code' => $item->employee?->employee_code ?? '—',
                'attendance_date' => $item->attendance_date->toDateString(),
                'request_type' => $item->request_type->value,
                'status' => $item->status->value,
                'reason' => $item->reason,
                'requester' => $item->requestedBy?->name ?? '—',
                'requested_at' => $item->requested_at->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                'item_count' => $item->items->count(),
                'can_cancel' => $user->can('cancel', $item),
            ]),
            'filters' => array_merge(['search' => '', 'date_from' => '', 'date_to' => '', 'request_type' => '', 'status' => '', 'requester' => ''], $filters),
            'types' => $this->options(AttendanceRegularizationType::cases()),
            'statuses' => $this->options(AttendanceRegularizationStatus::cases()),
            'company' => $activeCompany->only('id', 'name', 'timezone'),
            'canCreate' => $user->can('attendance_regularization.create'),
            'canCreateSelf' => $user->can('attendance_regularization.self_create'),
        ]);
    }

    public function create(Request $request, Company $company, WorkScheduleResolver $scheduleResolver): InertiaResponse
    {
        Gate::authorize('createForCompany', [AttendanceRegularizationRequest::class, $company]);
        $activeCompany = $this->assertSelectedCompany($company);
        $employees = $this->visibleEmployees($request->user(), $activeCompany)
            ->orderBy('last_name')->orderBy('first_name')->limit(50)
            ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name'])
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->full_name])
            ->values();

        return Inertia::render('attendance-regularizations/create', [
            'company' => $activeCompany->only('id', 'name', 'timezone'),
            'employees' => $employees,
            'employeeSearch' => $request->string('employee_search')->trim()->toString(),
            'selectedEmployeeId' => $request->integer('employee_id') ?: null,
            'attendanceDate' => $request->string('attendance_date')->toString(),
            'context' => $this->requestContext($activeCompany, $request->integer('employee_id'), $request->string('attendance_date')->toString(), $scheduleResolver, $request->user()),
            'self' => false,
            'types' => $this->options(AttendanceRegularizationType::cases()),
            'punchTypes' => $this->options(AttendancePunchType::cases()),
        ]);
    }

    public function createSelf(Request $request, Company $company, WorkScheduleResolver $scheduleResolver): InertiaResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $employee = $activeCompany->employees()->where('user_id', $request->user()->id)->firstOrFail();
        Gate::authorize('createSelf', [AttendanceRegularizationRequest::class, $activeCompany, $employee]);

        return Inertia::render('attendance-regularizations/create', [
            'company' => $activeCompany->only('id', 'name', 'timezone'),
            'employees' => [],
            'employeeSearch' => '',
            'selectedEmployeeId' => $employee->id,
            'attendanceDate' => $request->string('attendance_date')->toString(),
            'context' => $this->requestContext($activeCompany, $employee->id, $request->string('attendance_date')->toString(), $scheduleResolver, $request->user(), selfRequest: true),
            'self' => true,
            'types' => $this->options(AttendanceRegularizationType::cases()),
            'punchTypes' => $this->options(AttendancePunchType::cases()),
        ]);
    }

    public function store(StoreAttendanceRegularizationRequest $request, Company $company, CreateRegularization $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $data = $request->validated();
        $employee = $activeCompany->employees()->findOrFail($data['employee_id']);
        Gate::authorize('createForEmployee', [AttendanceRegularizationRequest::class, $activeCompany, $employee]);
        $regularization = $create->handle($activeCompany, $employee, $request->user(), $data);

        return to_route('companies.attendance-regularizations.show', [$activeCompany, $regularization])->with('success', 'Attendance regularization request submitted.');
    }

    public function storeSelf(StoreSelfAttendanceRegularizationRequest $request, Company $company, CreateRegularization $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $employee = $activeCompany->employees()->where('user_id', $request->user()->id)->firstOrFail();
        $regularization = $create->handle($activeCompany, $employee, $request->user(), $request->validated(), selfRequest: true);

        return to_route('companies.attendance-regularizations.show', [$activeCompany, $regularization])->with('success', 'Attendance regularization request submitted.');
    }

    public function show(Request $request, Company $company, AttendanceRegularizationRequest $attendanceRegularizationRequest, AttendanceRegularizationHistoryContextQuery $historyContext): InertiaResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $attendanceRegularization = $attendanceRegularizationRequest;
        Gate::authorize('view', $attendanceRegularization);
        $attendanceRegularization->load(['employee', 'requestedBy', 'reviewedBy', 'items.attendancePunch', 'items.correction.approvedBy']);
        $record = $attendanceRegularization->employee?->dailyAttendanceRecords()
            ->where('company_id', $activeCompany->getKey())
            ->whereDate('attendance_date', $attendanceRegularization->attendance_date->toDateString())
            ->first();
        $historicalRecord = $historyContext->atRequestTime($attendanceRegularization, $activeCompany);

        return Inertia::render('attendance-regularizations/show', [
            'company' => $activeCompany->only('id', 'name', 'timezone'),
            'request' => [
                'id' => $attendanceRegularization->id,
                'employee' => $attendanceRegularization->employee?->full_name ?? 'Archived employee',
                'employee_code' => $attendanceRegularization->employee?->employee_code ?? '—',
                'attendance_date' => $attendanceRegularization->attendance_date->toDateString(),
                'request_type' => $attendanceRegularization->request_type->value,
                'status' => $attendanceRegularization->status->value,
                'reason' => $attendanceRegularization->reason,
                'employee_note' => $attendanceRegularization->employee_note,
                'requester' => $attendanceRegularization->requestedBy?->name ?? '—',
                'requested_at' => $attendanceRegularization->requested_at->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                'reviewer' => $attendanceRegularization->reviewedBy?->name,
                'reviewed_at' => $attendanceRegularization->reviewed_at?->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                'review_note' => $attendanceRegularization->review_note,
                'daily_record' => $record ? [
                    'status' => $record->status->value,
                    'record_state' => $record->record_state->value,
                    'calculated_at' => $record->calculated_at?->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                    'first_check_in' => $record->first_check_in_at?->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                    'last_check_out' => $record->last_check_out_at?->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                    'worked_minutes' => $record->worked_minutes,
                    'late_minutes' => $record->late_minutes,
                    'early_leave_minutes' => $record->early_leave_minutes,
                    'overtime_minutes' => $record->overtime_minutes,
                ] : null,
                'historical_record' => $historicalRecord,
                'historical_record_missing_reason' => $historicalRecord !== null
                    ? null
                    : ($attendanceRegularization->daily_attendance_record_id === null
                    ? 'No materialized attendance record existed when this request was submitted.'
                    : 'No attendance history snapshot is available from before this request was submitted. Current attendance is shown separately.'),
                'items' => $attendanceRegularization->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'action' => $item->action->value,
                    'original_punch' => $item->attendancePunch ? [
                        'type' => $item->attendancePunch->punch_type->value,
                        'punched_at' => $item->attendancePunch->punched_at->setTimezone($item->attendancePunch->timezone)->format('d M Y, h:i A'),
                        'timezone' => $item->attendancePunch->timezone,
                    ] : null,
                    'punch_type' => $item->punch_type?->value,
                    'requested_punched_at' => $item->requested_punched_at?->setTimezone($item->timezone)->format('d M Y, h:i A'),
                    'timezone' => $item->timezone,
                    'notes' => $item->notes,
                    'approved_correction' => $item->correction ? [
                        'action' => $item->correction->action->value,
                        'effective_punch_type' => $item->correction->effective_punch_type?->value,
                        'effective_punched_at' => $item->correction->effective_punched_at?->setTimezone($item->correction->timezone)->format('d M Y, h:i A'),
                        'approved_by' => $item->correction->approvedBy?->name,
                        'approved_at' => $item->correction->approved_at?->setTimezone($activeCompany->timezone ?: config('app.timezone'))->format('d M Y, h:i A'),
                    ] : null,
                ])->values(),
                'can_cancel' => $request->user()->can('cancel', $attendanceRegularization),
                'can_approve' => $request->user()->can('approve', $attendanceRegularization),
                'can_reject' => $request->user()->can('reject', $attendanceRegularization),
            ],
        ]);
    }

    public function approve(ApproveAttendanceRegularizationFormRequest $form, Company $company, AttendanceRegularizationRequest $attendanceRegularizationRequest, ApproveRegularization $approve, AttendanceRegularizationNotificationService $notifications): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        $regularization = $approve->handle($attendanceRegularizationRequest, $form->user(), $form->validated('review_note'));
        $notifications->correctionApproved($regularization);

        return back()->with('success', 'Attendance regularization approved and attendance recalculated.');
    }

    public function reject(RejectAttendanceRegularizationFormRequest $form, Company $company, AttendanceRegularizationRequest $attendanceRegularizationRequest, RejectRegularization $reject, AttendanceRegularizationNotificationService $notifications): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        $regularization = $reject->handle($attendanceRegularizationRequest, $form->user(), $form->validated('review_note'));
        $notifications->correctionRejected($regularization);

        return back()->with('success', 'Attendance regularization rejected.');
    }

    public function cancel(Request $request, Company $company, AttendanceRegularizationRequest $attendanceRegularizationRequest, CancelAttendanceRegularizationRequest $cancel): RedirectResponse
    {
        $attendanceRegularization = $attendanceRegularizationRequest;
        $this->assertSelectedCompany($company);
        Gate::authorize('cancel', $attendanceRegularization);
        $cancel->handle($attendanceRegularization);

        return back()->with('success', 'Attendance regularization request cancelled.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    private function visibleEmployees(User $user, Company $company): Builder|Relation
    {
        $query = $company->employees();
        if (! $user->can('employee.view')) {
            $this->restrictToManagerScope($query, $user, $company);
        }

        return $query;
    }

    private function scopeVisibleEmployees(Builder|Relation $query, User $user, Company $company): void
    {
        if ($user->can('employee.view')) {
            return;
        }

        if ($user->can('attendance_regularization.self_view')) {
            $query->whereHas('employee', fn (Builder $employees): Builder => $employees->where('user_id', $user->id));

            return;
        }

        $this->restrictToManagerScope($query, $user, $company);
    }

    private function restrictToManagerScope(Builder|Relation $query, User $user, Company $company): void
    {
        $managerIds = $user->employees()->where('company_id', $company->id)->select('id');
        $query->whereHas('employee', fn (Builder $employees): Builder => $employees
            ->where(fn (Builder $visible): Builder => $visible->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds)));
    }

    /** @param list<\BackedEnum> $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => ['value' => $case->value, 'label' => str($case->value)->replace('_', ' ')->title()->toString()], $cases);
    }

    /** @return array{record: ?array<string, mixed>, punches: list<array<string, mixed>>} */
    private function requestContext(Company $company, int $employeeId, string $attendanceDate, WorkScheduleResolver $scheduleResolver, User $user, bool $selfRequest = false): array
    {
        if (! $employeeId || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
            return ['record' => null, 'punches' => []];
        }

        $employee = $selfRequest
            ? $company->employees()->where('user_id', $user->id)->whereKey($employeeId)->first()
            : $this->visibleEmployees($user, $company)->whereKey($employeeId)->first();
        if (! $employee) {
            return ['record' => null, 'punches' => []];
        }

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $date = CarbonImmutable::parse($attendanceDate, $timezone)->startOfDay();
        $record = $employee->dailyAttendanceRecords()->whereDate('attendance_date', $attendanceDate)->first();
        try {
            $schedule = $scheduleResolver->resolve($employee, $date);
            $window = AttendancePunchWindow::forDate($date, $schedule->expectedStart, $schedule->expectedEnd);
        } catch (NoWorkScheduleAssignmentException) {
            $window = AttendancePunchWindow::forDate($date, null, null);
        }

        $punchQuery = AttendancePunch::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $window->start->utc());
        $window->includesEnd
            ? $punchQuery->where('punched_at', '<=', $window->end->utc())
            : $punchQuery->where('punched_at', '<', $window->end->utc());
        $punches = $punchQuery->orderBy('punched_at')->orderBy('id')->get()
            ->map(fn (AttendancePunch $punch): array => [
                'id' => $punch->id,
                'punch_type' => $punch->punch_type->value,
                'punched_at' => $punch->punched_at->setTimezone($punch->timezone)->format('Y-m-d H:i:s'),
                'timezone' => $punch->timezone,
            ])->values()->all();

        return [
            'record' => $record ? [
                'status' => $record->status->value,
                'record_state' => $record->record_state->value,
                'worked_minutes' => $record->worked_minutes,
                'first_check_in' => $record->first_check_in_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'last_check_out' => $record->last_check_out_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            ] : null,
            'punches' => $punches,
        ];
    }
}
