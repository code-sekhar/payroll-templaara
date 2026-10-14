<?php

namespace App\Policies;

use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Tenancy\ActiveCompany;

class LeaveRequestPolicy
{
    public function __construct(private readonly LeaveRequestReviewerScope $reviewerScope) {}

    public function viewAny(User $user, Company $company): bool
    {
        return $this->isActiveCompany($company) && $user->can('leave_request.view');
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if (app(ActiveCompany::class)->current()?->getKey() !== $leaveRequest->company_id) {
            return false;
        }

        $company = $leaveRequest->company;

        if ($company instanceof Company && $this->reviewerScope->allowsRequest($user, $leaveRequest, $company)) {
            return true;
        }

        return $user->can('leave_request.self_view')
            && (int) $leaveRequest->employee?->user_id === (int) $user->id;
    }

    public function createForCompany(User $user, Company $company): bool
    {
        return $this->isActiveCompany($company) && $user->can('leave_request.create');
    }

    public function createForEmployee(User $user, Company $company, Employee $employee): bool
    {
        return $this->createForCompany($user, $company) && (int) $employee->company_id === (int) $company->id && ! $employee->trashed();
    }

    public function createSelf(User $user, Company $company, Employee $employee): bool
    {
        return $this->isActiveCompany($company)
            && (int) $employee->company_id === (int) $company->id
            && (int) $employee->user_id === (int) $user->id
            && ! $employee->trashed()
            && $user->can('leave_request.self_create');
    }

    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        if (app(ActiveCompany::class)->current()?->getKey() !== $leaveRequest->company_id || $leaveRequest->status !== LeaveRequestStatus::Pending) {
            return false;
        }

        if ($user->can('leave_request.cancel')) {
            return true;
        }

        return $user->can('leave_request.self_cancel')
            && (int) $leaveRequest->employee?->user_id === (int) $user->id;
    }

    public function cancelApproved(User $user, LeaveRequest $leaveRequest): bool
    {
        $isApprovedOrApprovedCancellationRetry = $leaveRequest->status === LeaveRequestStatus::Approved
            || ($leaveRequest->status === LeaveRequestStatus::Cancelled
                && $leaveRequest->reviewed_by !== null
                && $leaveRequest->reviewed_at !== null
                && LeaveLedgerEntry::query()->where('company_id', $leaveRequest->company_id)
                    ->where('employee_id', $leaveRequest->employee_id)
                    ->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)
                    ->where('reference_type', LeaveRequest::class)
                    ->where('reference_id', $leaveRequest->getKey())
                    ->exists());

        return $isApprovedOrApprovedCancellationRetry
            && app(ActiveCompany::class)->current()?->getKey() === $leaveRequest->company_id
            && $user->can('leave_request.cancel')
            && (int) $leaveRequest->requested_by !== (int) $user->getKey()
            && (int) $leaveRequest->employee?->user_id !== (int) $user->getKey()
            && $this->view($user, $leaveRequest);
    }

    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->canReview($user, $leaveRequest, 'leave_request.approve');
    }

    public function submitApprovalDecision(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->canSubmitDecision($user, $leaveRequest, 'leave_request.approve');
    }

    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->canReview($user, $leaveRequest, 'leave_request.reject');
    }

    public function submitRejectionDecision(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->canSubmitDecision($user, $leaveRequest, 'leave_request.reject');
    }

    public function selfView(User $user, LeaveRequest $leaveRequest): bool
    {
        return app(ActiveCompany::class)->current()?->getKey() === $leaveRequest->company_id
            && $user->can('leave_request.self_view')
            && (int) $leaveRequest->employee?->user_id === (int) $user->id;
    }

    private function isActiveCompany(Company $company): bool
    {
        return app(ActiveCompany::class)->current()?->is($company) ?? false;
    }

    private function canReview(User $user, LeaveRequest $leaveRequest, string $permission): bool
    {
        return $leaveRequest->status === LeaveRequestStatus::Pending
            && (int) $leaveRequest->requested_by !== (int) $user->getKey()
            && (int) $leaveRequest->employee?->user_id !== (int) $user->getKey()
            && $user->can($permission)
            && $this->view($user, $leaveRequest);
    }

    private function canSubmitDecision(User $user, LeaveRequest $leaveRequest, string $permission): bool
    {
        return (int) $leaveRequest->requested_by !== (int) $user->getKey()
            && (int) $leaveRequest->employee?->user_id !== (int) $user->getKey()
            && $user->can($permission)
            && $this->view($user, $leaveRequest);
    }
}
