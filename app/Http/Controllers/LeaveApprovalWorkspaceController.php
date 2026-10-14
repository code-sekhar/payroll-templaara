<?php

namespace App\Http\Controllers;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveApprovalInboxQuery;
use App\Services\Leave\LeaveApprovalRequestDetailQuery;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LeaveApprovalWorkspaceController extends Controller
{
    public function index(
        Request $request,
        ActiveCompany $activeCompany,
        LeaveApprovalInboxQuery $inbox,
        LeaveRequestReviewerScope $scope,
    ): Response {
        $company = $this->activeCompany($activeCompany);
        Gate::authorize('viewAny', [LeaveRequest::class, $company]);

        $canFilterDepartments = $scope->canViewCompanyRequests($request->user(), $company);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', ...array_map(fn (LeaveRequestStatus $status): string => $status->value, LeaveRequestStatus::cases())])],
            'leave_type_id' => ['nullable', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $company->getKey())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'department_id' => $canFilterDepartments
                ? ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $company->getKey())]
                : ['prohibited'],
        ]);
        if (! array_key_exists('status', $validated)) {
            $validated['status'] = LeaveRequestStatus::Pending->value;
        }

        return Inertia::render('leave-approvals/index', $inbox->forReviewer($request->user(), $company, $validated, $scope));
    }

    public function show(
        Request $request,
        string $requestId,
        ActiveCompany $activeCompany,
        LeaveRequestReviewerScope $scope,
        LeaveApprovalRequestDetailQuery $detail,
        LeaveBalanceCalculator $balances,
        LeaveRequestReservationCalculator $reservations,
    ): Response {
        $company = $this->activeCompany($activeCompany);
        Gate::authorize('viewAny', [LeaveRequest::class, $company]);

        if (! ctype_digit($requestId)) {
            abort(404);
        }

        $leaveRequest = $detail->findModelForReviewer($request->user(), $company, (int) $requestId, $scope);
        abort_unless($leaveRequest instanceof LeaveRequest, 404);
        Gate::authorize('view', $leaveRequest);

        return Inertia::render('leave-approvals/show', $detail->presentForReviewer(
            $leaveRequest,
            $request->user(),
            $company,
            $balances,
            $reservations,
        ));
    }

    private function activeCompany(ActiveCompany $activeCompany): Company
    {
        $company = $activeCompany->current();
        abort_unless($company instanceof Company, 403);

        return $company;
    }
}
