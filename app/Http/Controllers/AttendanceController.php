<?php

namespace App\Http\Controllers;

use App\Enums\AttendancePunchType;
use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceRecordState;
use App\Http\Requests\Attendance\IndexAttendanceRequest;
use App\Http\Requests\Attendance\IndexMyAttendanceRequest;
use App\Models\AttendancePunch;
use App\Models\AttendancePunchCorrection;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendancePunchWindow;
use App\Services\Attendance\AttendanceReportQuery;
use App\Services\Attendance\EffectiveAttendancePunch;
use App\Services\Attendance\EffectiveAttendancePunchReader;
use App\Tenancy\ActiveCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AttendanceController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(IndexAttendanceRequest $request, Company $company, AttendanceReportQuery $reportQuery): InertiaResponse
    {
        $company = $this->assertSelectedCompany($company);
        $filters = $request->validated();
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $filters = array_merge([
            'date_from' => $now->startOfMonth()->toDateString(), 'date_to' => $now->endOfMonth()->toDateString(),
            'search' => '', 'department_id' => '', 'designation_id' => '', 'branch_id' => '',
            'status' => '', 'record_state' => '', 'regularization_status' => '',
        ], $filters);
        if (filled($request->query('date_from')) && blank($request->query('date_to'))) {
            $filters['date_to'] = $filters['date_from'];
        } elseif (filled($request->query('date_to')) && blank($request->query('date_from'))) {
            $filters['date_from'] = $filters['date_to'];
        }
        $query = $reportQuery->forCompany($company, $request->user(), $filters);
        $records = (clone $query)->addSelect([
            'regularization_status' => AttendanceRegularizationRequest::query()
                ->select('status')
                ->whereColumn('attendance_regularization_requests.company_id', 'daily_attendance_records.company_id')
                ->whereColumn('attendance_regularization_requests.employee_id', 'daily_attendance_records.employee_id')
                ->whereColumn('attendance_regularization_requests.attendance_date', 'daily_attendance_records.attendance_date')
                ->orderByDesc('id')->limit(1),
        ])->paginate(25)->withQueryString()->through(fn (DailyAttendanceRecord $record): array => $this->recordSummary($record, $timezone));

        return Inertia::render('attendance/index', [
            'records' => $records,
            'summary' => $reportQuery->summary($query, $company),
            'filters' => $filters,
            'options' => [
                'statuses' => $this->enumOptions(CalculatedAttendanceStatus::cases()),
                'record_states' => $this->enumOptions(DailyAttendanceRecordState::cases()),
                'regularization_statuses' => ['none', 'pending', 'approved', 'rejected', 'cancelled'],
                'departments' => $company->departments()->orderBy('name')->get(['id', 'name']),
                'designations' => $company->designations()->orderBy('name')->get(['id', 'name']),
                'branches' => $company->branches()->orderBy('name')->get(['id', 'name']),
            ],
            'company' => $company->only('id', 'name', 'timezone'),
        ]);
    }

    public function show(Company $company, DailyAttendanceRecord $dailyAttendanceRecord, EffectiveAttendancePunchReader $punchReader): InertiaResponse
    {
        $company = $this->assertSelectedCompany($company);
        abort_unless((int) $dailyAttendanceRecord->company_id === (int) $company->id, 404);
        Gate::authorize('view', $dailyAttendanceRecord);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $employee = $dailyAttendanceRecord->employee;
        $date = CarbonImmutable::parse($dailyAttendanceRecord->attendance_date->toDateString(), $timezone)->startOfDay();
        $expectedStart = $dailyAttendanceRecord->expected_start_at?->setTimezone($timezone);
        $expectedEnd = $dailyAttendanceRecord->expected_end_at?->setTimezone($timezone);
        $window = $expectedStart && $expectedEnd
            ? AttendancePunchWindow::forDate($date, $expectedStart, $expectedEnd)
            : new AttendancePunchWindow($date->subHours(AttendancePunchWindow::PRE_SHIFT_BUFFER_HOURS), $date->addDay()->addHours(AttendancePunchWindow::POST_SHIFT_BUFFER_HOURS));

        $rawPunches = AttendancePunch::query()
            ->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $window->start->utc())->where('punched_at', '<=', $window->end->utc())
            ->with('createdBy')->orderBy('punched_at')->orderBy('id')->get();
        $corrections = AttendancePunchCorrection::query()
            ->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->with(['originalAttendancePunch', 'approvedBy', 'regularizationRequest'])
            ->orderBy('id')->get();
        $timeline = $punchReader->read($employee, $date, $window);
        $regularizations = AttendanceRegularizationRequest::query()
            ->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->with(['requestedBy', 'reviewedBy'])->orderByDesc('requested_at')->limit(50)->get();
        $histories = DailyAttendanceRecordHistory::query()
            ->where('company_id', $company->id)->where('daily_attendance_record_id', $dailyAttendanceRecord->id)
            ->with(['actor', 'leaveRequest', 'leaveType'])->orderByDesc('id')->limit(50)->get();

        return Inertia::render('attendance/show', [
            'company' => $company->only('id', 'name', 'timezone'),
            'record' => $this->recordDetail($dailyAttendanceRecord, $timezone),
            'rawPunches' => $rawPunches->map(fn (AttendancePunch $punch): array => [
                'id' => $punch->id,
                'type' => $punch->punch_type->value,
                'punched_at' => $punch->punched_at->setTimezone($punch->timezone)->format('d M Y, h:i A'),
                'timezone' => $punch->timezone,
                'source' => $punch->source->value,
                'created_by' => $punch->createdBy?->name,
            ])->values(),
            'corrections' => $corrections->map(fn (AttendancePunchCorrection $correction): array => [
                'id' => $correction->id,
                'action' => $correction->action->value,
                'original_type' => $correction->originalAttendancePunch?->punch_type?->value,
                'original_at' => $correction->originalAttendancePunch?->punched_at?->setTimezone($correction->originalAttendancePunch->timezone)->format('d M Y, h:i A'),
                'effective_type' => $correction->effective_punch_type?->value,
                'effective_at' => $correction->effective_punched_at?->setTimezone($correction->timezone)->format('d M Y, h:i A'),
                'approved_by' => $correction->approvedBy?->name,
                'approved_at' => $correction->approved_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'request_id' => $correction->attendance_regularization_request_id,
            ])->values(),
            'effectivePunches' => collect($timeline)->map(fn (AttendancePunch|EffectiveAttendancePunch $event): array => $this->timelineEvent($event))->values(),
            'regularizations' => $regularizations->map(fn (AttendanceRegularizationRequest $item): array => [
                'id' => $item->id,
                'type' => $item->request_type->value,
                'status' => $item->status->value,
                'requested_by' => $item->requestedBy?->name,
                'requested_at' => $item->requested_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'reviewed_by' => $item->reviewedBy?->name,
                'reviewed_at' => $item->reviewed_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'url' => route('companies.attendance-regularizations.show', [$company, $item]),
            ])->values(),
            'histories' => $histories->map(fn (DailyAttendanceRecordHistory $history): array => [
                'calculated_at' => $history->calculated_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'status' => $history->status->value,
                'record_state' => $history->record_state->value,
                'worked_minutes' => $history->worked_minutes,
                'late_minutes' => $history->late_minutes,
                'early_leave_minutes' => $history->early_leave_minutes,
                'overtime_minutes' => $history->overtime_minutes,
                'leave_request_id' => $history->leave_request_id,
                'leave_type' => $history->leaveType?->name,
                'leave_amount' => $history->leave_amount,
                'leave_is_paid' => $history->leave_is_paid,
                'reason' => $history->change_reason->value,
                'actor' => $history->actor?->name,
            ])->values(),
        ]);
    }

    public function myAttendance(IndexMyAttendanceRequest $request, Company $company): InertiaResponse
    {
        $company = $this->assertSelectedCompany($company);
        Gate::authorize('viewOwnAny', [DailyAttendanceRecord::class, $company]);
        $employee = $this->employeeForUser($company, $request->user());
        $filters = $request->validated();
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $filters = array_merge(['date_from' => $now->startOfMonth()->toDateString(), 'date_to' => $now->endOfMonth()->toDateString(), 'status' => ''], $filters);
        if (filled($request->query('date_from')) && blank($request->query('date_to'))) {
            $filters['date_to'] = $filters['date_from'];
        } elseif (filled($request->query('date_to')) && blank($request->query('date_from'))) {
            $filters['date_from'] = $filters['date_to'];
        }

        $query = $company->dailyAttendanceRecords()->with(['employee.department', 'employee.designation', 'employee.branch', 'shift', 'workSchedule', 'leaveType'])->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$filters['date_from'], $filters['date_to']]);
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        $records = $query->addSelect([
            'regularization_status' => AttendanceRegularizationRequest::query()->select('status')
                ->whereColumn('attendance_regularization_requests.company_id', 'daily_attendance_records.company_id')
                ->whereColumn('attendance_regularization_requests.employee_id', 'daily_attendance_records.employee_id')
                ->whereColumn('attendance_regularization_requests.attendance_date', 'daily_attendance_records.attendance_date')
                ->orderByDesc('id')->limit(1),
        ])->orderByDesc('attendance_date')->paginate(25)->withQueryString()
            ->through(fn (DailyAttendanceRecord $record): array => $this->recordSummary($record, $timezone));
        $today = $now->toDateString();
        $todayRecord = $company->dailyAttendanceRecords()->with(['employee.department', 'employee.designation', 'employee.branch', 'shift', 'workSchedule', 'leaveType'])
            ->where('employee_id', $employee->id)->whereDate('attendance_date', $today)
            ->addSelect([
                'regularization_status' => AttendanceRegularizationRequest::query()->select('status')
                    ->whereColumn('attendance_regularization_requests.company_id', 'daily_attendance_records.company_id')
                    ->whereColumn('attendance_regularization_requests.employee_id', 'daily_attendance_records.employee_id')
                    ->whereColumn('attendance_regularization_requests.attendance_date', 'daily_attendance_records.attendance_date')
                    ->orderByDesc('id')->limit(1),
            ])->first();
        $latestPunch = $employee->attendancePunches()->latest('punched_at')->latest('id')->first();
        $nextPunch = $latestPunch?->punch_type === AttendancePunchType::CheckIn ? AttendancePunchType::CheckOut : AttendancePunchType::CheckIn;

        return Inertia::render('attendance/my-attendance', [
            'company' => $company->only('id', 'name', 'timezone'),
            'today' => $today,
            'todayRecord' => $todayRecord ? $this->recordSummary($todayRecord, $timezone) : null,
            'nextPunchType' => $nextPunch->value,
            'selfPunchUrl' => route('companies.attendance-punches.self', $company),
            'records' => $records,
            'filters' => $filters,
            'statuses' => $this->enumOptions(CalculatedAttendanceStatus::cases()),
        ]);
    }

    public function showMine(Request $request, Company $company, DailyAttendanceRecord $dailyAttendanceRecord, EffectiveAttendancePunchReader $punchReader): InertiaResponse
    {
        $company = $this->assertSelectedCompany($company);
        abort_unless((int) $dailyAttendanceRecord->company_id === (int) $company->id, 404);
        Gate::authorize('viewOwn', $dailyAttendanceRecord);
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $employee = $dailyAttendanceRecord->employee;
        abort_unless((int) $employee?->user_id === (int) request()->user()->getKey(), 404);
        $date = CarbonImmutable::parse($dailyAttendanceRecord->attendance_date->toDateString(), $timezone)->startOfDay();
        $window = $this->punchWindow($dailyAttendanceRecord, $date, $timezone);
        $timeline = $punchReader->read($employee, $date, $window);
        $regularizations = AttendanceRegularizationRequest::query()->where('company_id', $company->id)
            ->where('employee_id', $employee->id)->whereDate('attendance_date', $date->toDateString())
            ->with(['requestedBy', 'reviewedBy'])->orderByDesc('requested_at')->limit(50)->get();

        return Inertia::render('attendance/my-attendance-show', [
            'company' => $company->only('id', 'name', 'timezone'),
            'record' => $this->recordDetail($dailyAttendanceRecord, $timezone),
            'effectivePunches' => collect($timeline)->map(fn (AttendancePunch|EffectiveAttendancePunch $event): array => $this->timelineEvent($event))->values(),
            'regularizations' => $regularizations->map(fn (AttendanceRegularizationRequest $item): array => [
                'id' => $item->id,
                'type' => $item->request_type->value,
                'status' => $item->status->value,
                'requested_at' => $item->requested_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'reviewed_at' => $item->reviewed_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'url' => route('companies.attendance-regularizations.show', [$company, $item]),
            ])->values(),
            'regularizationCreateUrl' => route('companies.attendance-regularizations.self.create', [$company, 'attendance_date' => $date->toDateString()]),
            'canCreateRegularization' => $request->user()->can('attendance_regularization.self_create'),
        ]);
    }

    private function recordSummary(DailyAttendanceRecord $record, string $timezone): array
    {
        return [
            'id' => $record->id,
            'attendance_date' => $record->attendance_date->toDateString(),
            'employee' => $record->employee?->full_name ?? 'Archived employee',
            'employee_code' => $record->employee?->employee_code ?? '—',
            'department' => $record->employee?->department?->name,
            'branch' => $record->employee?->branch?->name,
            'designation' => $record->employee?->designation?->name,
            'shift' => $record->shift?->name,
            'schedule' => $record->workSchedule?->name,
            'first_check_in' => $record->first_check_in_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'last_check_out' => $record->last_check_out_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'worked_minutes' => $record->worked_minutes,
            'expected_minutes' => $record->expected_minutes,
            'late_minutes' => $record->late_minutes,
            'early_leave_minutes' => $record->early_leave_minutes,
            'overtime_minutes' => $record->overtime_minutes,
            'status' => $record->status->value,
            'leave_type' => $record->leaveType?->name,
            'leave_amount' => $record->leave_amount,
            'record_state' => $record->record_state->value,
            'regularization_status' => $record->getAttribute('regularization_status'),
        ];
    }

    private function recordDetail(DailyAttendanceRecord $record, string $timezone): array
    {
        return [
            ...$this->recordSummary($record, $timezone),
            'expected_start' => $record->expected_start_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'expected_end' => $record->expected_end_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'calculated_at' => $record->calculated_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'finalized_at' => $record->finalized_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'calculation_version' => $record->calculation_version,
            'employee' => $record->employee ? [
                'name' => $record->employee->full_name,
                'employee_code' => $record->employee->employee_code,
                'department' => $record->employee->department?->name,
                'designation' => $record->employee->designation?->name,
                'branch' => $record->employee->branch?->name,
            ] : null,
            'attendance_policy' => $record->attendancePolicy?->name,
            'leave_request_id' => $record->leave_request_id,
            'leave_request_status' => $record->leaveRequest?->status?->value,
            'leave_is_paid' => $record->leave_is_paid,
        ];
    }

    private function timelineEvent(AttendancePunch|EffectiveAttendancePunch $event): array
    {
        $effective = $event instanceof EffectiveAttendancePunch ? $event : EffectiveAttendancePunch::fromRaw($event);
        $timezone = $effective->correction?->timezone ?? $effective->rawPunch?->timezone ?? config('app.timezone', 'UTC');

        return [
            'type' => $effective->punch_type->value,
            'punched_at' => $effective->punched_at->setTimezone($timezone)->format('d M Y, h:i A'),
            'timezone' => $timezone,
            'source' => $effective->eventKind === 'raw' ? $effective->rawPunch?->source->value : 'regularization',
            'event_kind' => $effective->eventKind,
        ];
    }

    private function punchWindow(DailyAttendanceRecord $record, CarbonImmutable $date, string $timezone): AttendancePunchWindow
    {
        $start = $record->expected_start_at?->setTimezone($timezone);
        $end = $record->expected_end_at?->setTimezone($timezone);

        return $start && $end
            ? AttendancePunchWindow::forDate($date, $start, $end)
            : new AttendancePunchWindow($date->subHours(AttendancePunchWindow::PRE_SHIFT_BUFFER_HOURS), $date->addDay()->addHours(AttendancePunchWindow::POST_SHIFT_BUFFER_HOURS));
    }

    private function employeeForUser(Company $company, User $user): Employee
    {
        return $company->employees()->where('user_id', $user->id)->firstOrFail();
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    /** @param array<\BackedEnum> $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => ['value' => $case->value, 'label' => str($case->value)->replace('_', ' ')->title()->toString()], $cases);
    }
}
