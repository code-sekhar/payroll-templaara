<?php

namespace App\Http\Controllers;

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\RejectLeaveRequest;
use App\Http\Requests\LeaveRequest\ApproveLeaveRequestRequest;
use App\Http\Requests\LeaveRequest\RejectLeaveRequestRequest;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;

class LeaveApprovalDecisionController extends Controller
{
    public function approve(
        ApproveLeaveRequestRequest $request,
        Company $company,
        LeaveRequest $leaveRequest,
        ActiveCompany $activeCompany,
        ApproveLeaveRequest $approval,
    ): RedirectResponse {
        $this->assertActiveRequest($company, $leaveRequest, $activeCompany);
        $approval->handle($leaveRequest, $request->user(), $company, $request->validated('review_note'));

        return redirect()->route('leave.approvals.show', $leaveRequest->getKey())
            ->with('success', 'Leave request approved. Its reserved amount was applied through the Leave ledger.');
    }

    public function reject(
        RejectLeaveRequestRequest $request,
        Company $company,
        LeaveRequest $leaveRequest,
        ActiveCompany $activeCompany,
        RejectLeaveRequest $rejection,
    ): RedirectResponse {
        $this->assertActiveRequest($company, $leaveRequest, $activeCompany);
        $rejection->handle($leaveRequest, $request->user(), $company, $request->validated('review_note'));

        return redirect()->route('leave.approvals.show', $leaveRequest->getKey())
            ->with('success', 'Leave request rejected. Its pending reservation has been released.');
    }

    private function assertActiveRequest(Company $company, LeaveRequest $leaveRequest, ActiveCompany $activeCompany): void
    {
        abort_unless($activeCompany->current()?->is($company), 404);
        abort_unless((int) $leaveRequest->company_id === (int) $company->getKey(), 404);
    }
}
