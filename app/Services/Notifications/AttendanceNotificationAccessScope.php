<?php

namespace App\Services\Notifications;

use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use App\Notifications\AttendanceCorrectionRejected;
use App\Notifications\AttendanceCorrectionSubmitted;
use App\Services\Employees\EmployeePortalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

final class AttendanceNotificationAccessScope
{
    public function __construct(private readonly EmployeePortalAccess $employeePortalAccess) {}

    /**
     * @return MorphMany<DatabaseNotification, User>
     */
    public function forRecipient(User $user, Company $company): MorphMany
    {
        $visibleRequests = $this->visibleRequests($user, $company);
        $employee = $this->employeePortalAccess->employeeFor($user, $company);
        $notificationReference = $this->notificationRequestReference();
        $query = $user->notifications()
            ->where('notifications.company_id', $company->getKey())
            ->where(function (Builder $notifications) use ($visibleRequests, $employee, $notificationReference): void {
                $notifications->where(function (Builder $submitted) use ($visibleRequests, $notificationReference): void {
                    $submitted->where('notifications.type', AttendanceCorrectionSubmitted::class)
                        ->whereIn(DB::raw($notificationReference), (clone $visibleRequests)->selectRaw('CAST(attendance_regularization_requests.id AS TEXT)'));
                });

                if ($employee instanceof Employee) {
                    $notifications->orWhere(function (Builder $approved) use ($visibleRequests, $employee, $notificationReference): void {
                        $approved->where('notifications.type', AttendanceCorrectionApproved::class)
                            ->whereIn(DB::raw($notificationReference), (clone $visibleRequests)
                                ->where('attendance_regularization_requests.employee_id', $employee->getKey())
                                ->where('attendance_regularization_requests.status', 'approved')
                                ->selectRaw('CAST(attendance_regularization_requests.id AS TEXT)'));
                    })->orWhere(function (Builder $rejected) use ($visibleRequests, $employee, $notificationReference): void {
                        $rejected->where('notifications.type', AttendanceCorrectionRejected::class)
                            ->whereIn(DB::raw($notificationReference), (clone $visibleRequests)
                                ->where('attendance_regularization_requests.employee_id', $employee->getKey())
                                ->where('attendance_regularization_requests.status', 'rejected')
                                ->selectRaw('CAST(attendance_regularization_requests.id AS TEXT)'));
                    });
                }
            });

        return $query;
    }

    /** @return Builder<AttendanceRegularizationRequest> */
    private function visibleRequests(User $user, Company $company): Builder
    {
        $query = AttendanceRegularizationRequest::query()
            ->where('attendance_regularization_requests.company_id', $company->getKey())
            ->where(function (Builder $visibility) use ($user, $company): void {
                $canReview = $user->can('attendance_regularization.view');

                if ($canReview && $user->can('employee.view')) {
                    $visibility->whereRaw('1 = 1');
                } elseif ($canReview && $user->can('employee.view_team')) {
                    $managerIds = $user->employees()
                        ->where('employees.company_id', $company->getKey())
                        ->select('employees.id');

                    $visibility->whereHas('employee', function (Builder $employees) use ($company, $managerIds): void {
                        $employees->where('employees.company_id', $company->getKey())
                            ->whereNull('employees.deleted_at')
                            ->where(function (Builder $visibleEmployee) use ($managerIds): void {
                                $visibleEmployee->whereIn('employees.id', $managerIds)
                                    ->orWhereIn('employees.manager_id', $managerIds);
                            });
                    });
                } else {
                    $visibility->whereRaw('1 = 0');
                }

                if ($user->can('attendance_regularization.self_view')) {
                    $visibility->orWhereHas('employee', fn (Builder $employees): Builder => $employees
                        ->where('employees.company_id', $company->getKey())
                        ->where('employees.user_id', $user->getKey()));
                }
            });

        return $query;
    }

    private function notificationRequestReference(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "notifications.data::jsonb ->> 'regularization_id'",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.regularization_id'))",
            default => "CAST(json_extract(notifications.data, '$.regularization_id') AS TEXT)",
        };
    }
}
