<?php

namespace App\Http\Controllers;

use App\Actions\LeaveRequests\CancelLeaveRequest;
use App\Actions\LeaveRequests\CreateLeaveRequest;
use App\Exceptions\Holidays\InvalidHolidayCalendarConfigurationException;
use App\Exceptions\Leave\LeaveRequestConfigurationException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Http\Requests\LeaveRequest\CancelLeaveRequestRequest;
use App\Http\Requests\LeaveRequest\StoreLeaveRequestRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use App\Tenancy\ActiveCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MyLeaveController extends Controller
{
    public function index(Request $request, Company $company, LeaveBalanceCalculator $calculator, LeaveRequestReservationCalculator $reservations): Response
    {
        $employee = $this->selfEmployee($request, $company);
        abort_unless($request->user()->can('leave_request.self_view'), 403);
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,cancelled'], 'leave_type_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $requests = $employee->leaveRequests()->with(['leaveType:id,name', 'days'])->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where('leave_type_id', $id))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date))
            ->orderByDesc('requested_at')->paginate(15)->withQueryString();
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        $types = $company->leaveTypes()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $balances = $types->map(function (LeaveType $type) use ($employee, $today, $calculator, $reservations): array {
            $assignment = $employee->leavePolicyAssignmentForDate($type, $today);
            $account = $assignment ? EmployeeLeaveBalanceAccount::query()->where('employee_leave_policy_assignment_id', $assignment->id)
                ->where('status', 'open')->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->first() : null;
            if (! $account) {
                return ['leaveTypeId' => $type->id, 'name' => $type->name, 'configured' => false, 'balance' => null, 'pending' => null, 'available' => null];
            }
            $balance = $calculator->calculate($account)->balance;
            $reserved = $reservations->pendingCents($account);

            return ['leaveTypeId' => $type->id, 'name' => $type->name, 'configured' => true, 'balance' => $balance,
                'pending' => LeaveAmount::fromCents($reserved), 'available' => LeaveAmount::fromCents(LeaveAmount::toCents($balance) - $reserved)];
        });

        return Inertia::render('leave-requests/my-leave', ['requests' => $requests, 'filters' => $filters, 'leaveTypes' => $types, 'balances' => $balances,
            'company' => ['id' => $company->id], 'createUrl' => route('companies.my-leave.create', $company), 'showBaseUrl' => route('companies.my-leave.index', $company)]);
    }

    public function create(Request $request, Company $company): Response
    {
        $employee = $this->selfEmployee($request, $company);
        Gate::authorize('createSelf', [LeaveRequest::class, $company, $employee]);

        return Inertia::render('leave-requests/my-create', [
            'leaveTypes' => $company->leaveTypes()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'allows_half_day']),
            'storeUrl' => route('companies.my-leave.store', $company), 'backUrl' => route('companies.my-leave.index', $company),
            'missingConfigurationMessage' => 'If no policy or balance account is configured for the selected dates, contact HR.',
        ]);
    }

    public function store(StoreLeaveRequestRequest $request, Company $company, CreateLeaveRequest $action): RedirectResponse
    {
        $employee = $this->selfEmployee($request, $company);
        $data = $request->validated();
        $type = $company->leaveTypes()->whereKey($data['leave_type_id'])->firstOrFail();
        try {
            $leaveRequest = $action->handle($employee, $type, $data['start_date'], $data['end_date'], $data['duration'], $data['reason'] ?? null, $data['employee_note'] ?? null, $request->user());
        } catch (LeaveRequestConfigurationException|NoWorkScheduleAssignmentException|InvalidWorkScheduleConfigurationException|InvalidHolidayCalendarConfigurationException $exception) {
            throw ValidationException::withMessages(['start_date' => $exception->getMessage()]);
        }

        return redirect()->route('companies.my-leave.show', [$company, $leaveRequest])->with('success', 'Leave request submitted.');
    }

    public function show(Request $request, Company $company, LeaveRequest $leaveRequest): Response
    {
        $this->assertCompany($request, $company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);
        Gate::authorize('selfView', $leaveRequest);
        $leaveRequest->load(['employee', 'leaveType', 'leavePolicy', 'requester', 'canceller', 'reviewedBy', 'balanceAccount', 'days.workSchedule', 'days.holiday']);

        return Inertia::render('leave-requests/my-show', [
            'leaveRequest' => $this->serializeRequest($leaveRequest), 'balance' => null,
            'canCancel' => $request->user()->can('cancel', $leaveRequest), 'canApprove' => false, 'canReject' => false,
            'cancelUrl' => route('companies.my-leave.cancel', [$company, $leaveRequest]),
            'approveUrl' => null, 'rejectUrl' => null, 'ledgerConsumption' => null,
            'backUrl' => route('companies.my-leave.index', $company),
        ]);
    }

    public function cancel(CancelLeaveRequestRequest $request, Company $company, LeaveRequest $leaveRequest, CancelLeaveRequest $action): RedirectResponse
    {
        $this->assertCompany($request, $company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);
        $action->handle($leaveRequest, $request->user(), $request->validated('cancellation_reason'));

        return back()->with('success', 'Leave request cancelled.');
    }

    private function selfEmployee(Request $request, Company $company): Employee
    {
        $this->assertCompany($request, $company);

        return $company->employees()->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function assertCompany(Request $request, Company $company): void
    {
        abort_unless(app(ActiveCompany::class)->current()?->is($company), 404);
    }

    /** @return array<string, mixed> */
    private function serializeRequest(LeaveRequest $request): array
    {
        return [
            'id' => $request->id,
            'employee' => ['id' => $request->employee->id, 'name' => $request->employee->full_name, 'code' => $request->employee->employee_code],
            'leaveType' => ['id' => $request->leaveType->id, 'name' => $request->leaveType->name, 'code' => $request->leaveType->code],
            'leavePolicy' => ['name' => $request->leavePolicy->name, 'code' => $request->leavePolicy->code],
            'assignmentId' => $request->employee_leave_policy_assignment_id, 'balanceAccountId' => $request->leave_balance_account_id,
            'startDate' => $request->start_date->toDateString(), 'endDate' => $request->end_date->toDateString(),
            'requestedDays' => (string) $request->requested_days, 'duration' => $request->duration, 'status' => $request->status->value,
            'reason' => $request->reason, 'employeeNote' => $request->employee_note, 'requestedBy' => $request->requester?->name,
            'requestedAt' => $request->requested_at?->toDateTimeString(), 'cancelledBy' => $request->canceller?->name,
            'cancelledAt' => $request->cancelled_at?->toDateTimeString(), 'cancellationReason' => $request->cancellation_reason,
            'reviewedBy' => $request->reviewedBy?->name, 'reviewedAt' => $request->reviewed_at?->toDateTimeString(), 'reviewNote' => $request->review_note,
            'days' => $request->days->map(fn ($day): array => ['date' => $day->leave_date->toDateString(), 'type' => $day->day_type->value,
                'amount' => (string) $day->requested_amount, 'schedule' => $day->workSchedule?->name, 'holiday' => $day->holiday?->name])->all(),
        ];
    }
}
