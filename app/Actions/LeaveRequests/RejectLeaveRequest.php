<?php

namespace App\Actions\LeaveRequests;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Services\Notifications\LeaveLifecycleNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RejectLeaveRequest
{
    public function __construct(
        private readonly LeaveLifecycleNotificationService $notifications,
        private readonly LeaveRequestReviewerScope $reviewerScope,
    ) {}

    public function handle(LeaveRequest $leaveRequest, User $reviewer, Company $activeCompany, string $reviewNote): LeaveRequest
    {
        $reviewNote = trim($reviewNote);
        if (mb_strlen($reviewNote) < 5) {
            throw ValidationException::withMessages(['review_note' => 'Provide a rejection reason of at least five characters.']);
        }

        return DB::transaction(function () use ($leaveRequest, $reviewer, $activeCompany, $reviewNote): LeaveRequest {
            $request = LeaveRequest::query()->where('company_id', $activeCompany->getKey())
                ->whereKey($leaveRequest->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Only pending leave requests can be rejected.']);
            }
            if ((int) $request->requested_by === (int) $reviewer->getKey()) {
                throw ValidationException::withMessages(['request' => 'A requester cannot review their own leave request.']);
            }
            $employee = Employee::withTrashed()->where('company_id', $request->company_id)
                ->whereKey($request->employee_id)->lockForUpdate()->first();
            if (! $employee) {
                throw ValidationException::withMessages(['request' => 'The employee reference is no longer valid.']);
            }
            if (! $this->reviewerScope->allowsDecision($reviewer, $request, $activeCompany, $employee, 'leave_request.reject')) {
                throw new AuthorizationException('You are no longer authorized to reject this leave request.');
            }

            $request->forceFill([
                'status' => LeaveRequestStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now('UTC'),
                'review_note' => trim($reviewNote),
            ])->save();

            $this->notifications->requestRejected($request);

            return $request->refresh()->load(['employee', 'leaveType', 'leavePolicy', 'assignment', 'balanceAccount', 'requester', 'reviewedBy', 'days']);
        }, attempts: 3);
    }
}
