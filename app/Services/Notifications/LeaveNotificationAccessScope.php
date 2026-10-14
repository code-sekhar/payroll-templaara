<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestApproved;
use App\Notifications\LeaveRequestRejected;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\LeaveRequestWithdrawn;
use App\Services\Employees\EmployeePortalAccess;
use App\Services\Leave\LeaveRequestReviewerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

final class LeaveNotificationAccessScope
{
    public function __construct(
        private readonly EmployeePortalAccess $employeePortalAccess,
        private readonly LeaveRequestReviewerScope $reviewerScope,
    ) {}

    /** @return MorphMany<DatabaseNotification, User> */
    public function forRecipient(User $user, Company $company): MorphMany
    {
        $reviewerRequests = LeaveRequest::query()->where('leave_requests.company_id', $company->getKey());
        if ($user->can('leave_request.view')) {
            $reviewerRequests = $this->reviewerScope->apply($reviewerRequests, $user, $company);
        } else {
            $reviewerRequests->whereRaw('1 = 0');
        }

        $employee = null;
        if ($user->can('leave_request.self_view')) {
            $employee = $this->employeePortalAccess->employeeFor($user, $company);
        }

        $reference = $this->requestReference();
        $notifications = $user->notifications()->where('notifications.company_id', $company->getKey());

        return $notifications->where(function (Builder $types) use ($reviewerRequests, $employee, $company, $reference): void {
            $types->where(function (Builder $reviewerNotifications) use ($reviewerRequests, $reference): void {
                $reviewerNotifications->whereIn('notifications.type', [LeaveRequestSubmitted::class, LeaveRequestWithdrawn::class])
                    ->whereIn(DB::raw($reference), (clone $reviewerRequests)->selectRaw('CAST(leave_requests.id AS TEXT)'));
            });

            if ($employee instanceof Employee) {
                $types->orWhere(function (Builder $employeeNotifications) use ($employee, $company, $reference): void {
                    $employeeRequests = LeaveRequest::query()
                        ->where('leave_requests.company_id', $company->getKey())
                        ->where('leave_requests.employee_id', $employee->getKey());

                    $employeeNotifications->whereIn('notifications.type', [LeaveRequestApproved::class, LeaveRequestRejected::class])
                        ->whereIn(DB::raw($reference), $employeeRequests->selectRaw('CAST(leave_requests.id AS TEXT)'));
                });
            }
        });
    }

    private function requestReference(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "notifications.data::jsonb ->> 'leave_request_id'",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.leave_request_id'))",
            default => "CAST(json_extract(notifications.data, '$.leave_request_id') AS TEXT)",
        };
    }
}
