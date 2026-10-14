<?php

namespace App\Actions\LeaveRequests;

use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Notifications\LeaveLifecycleNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelLeaveRequest
{
    public function __construct(private readonly LeaveLifecycleNotificationService $notifications) {}

    public function handle(LeaveRequest $leaveRequest, User $actor, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $actor, $reason): LeaveRequest {
            $request = LeaveRequest::query()->where('company_id', $leaveRequest->company_id)
                ->whereKey($leaveRequest->getKey())->lockForUpdate()->firstOrFail();
            Employee::withTrashed()->whereKey($request->employee_id)->where('company_id', $request->company_id)->lockForUpdate()->firstOrFail();
            if ($request->status !== LeaveRequestStatus::Pending) {
                throw ValidationException::withMessages(['cancellation_reason' => 'Only pending leave requests can be cancelled.']);
            }

            $request->forceFill([
                'status' => LeaveRequestStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ])->save();

            $this->notifications->requestWithdrawn($request, $actor);

            return $request->load(['employee', 'leaveType', 'leavePolicy', 'assignment', 'balanceAccount', 'requester', 'canceller', 'days']);
        }, 3);
    }
}
