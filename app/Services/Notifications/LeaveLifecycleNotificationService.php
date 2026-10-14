<?php

namespace App\Services\Notifications;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestApproved;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\LeaveRequestRejected;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\LeaveRequestWithdrawn;
use App\Services\Employees\EmployeePortalAccess;
use App\Services\Leave\LeaveRequestReviewerScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

final class LeaveLifecycleNotificationService
{
    public function __construct(
        private readonly EmployeePortalAccess $employeePortalAccess,
        private readonly LeaveRequestReviewerScope $reviewerScope,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    public function requestSubmitted(LeaveRequest $request, User $requester): void
    {
        $company = $this->companyFor($request);
        if (! $company instanceof Company || $request->status !== LeaveRequestStatus::Pending) {
            return;
        }

        $this->withCompanyPermissions((int) $company->getKey(), function () use ($request, $requester, $company): void {
            $employee = $this->employeePortalAccess->employeeFor($requester, $company);

            if ((int) $request->requested_by !== (int) $requester->getKey()
                || ! $employee instanceof Employee || (int) $employee->getKey() !== (int) $request->employee_id
                || ! $requester->can('leave_request.self_view')) {
                return;
            }

            $this->notifyReviewers($request, $company, LeaveRequestSubmitted::class, 'submitted');
        });
    }

    public function requestApproved(LeaveRequest $request): void
    {
        $this->notifyEmployee($request, LeaveRequestApproved::class, 'approved');
    }

    public function requestRejected(LeaveRequest $request): void
    {
        $this->notifyEmployee($request, LeaveRequestRejected::class, 'rejected');
    }

    public function requestWithdrawn(LeaveRequest $request, User $actor): void
    {
        if ($request->status !== LeaveRequestStatus::Cancelled || (int) $request->cancelled_by !== (int) $actor->getKey()) {
            return;
        }

        $company = $this->companyFor($request);
        if (! $company instanceof Company) {
            return;
        }

        $this->withCompanyPermissions((int) $company->getKey(), function () use ($request, $actor, $company): void {
            $employee = $this->employeePortalAccess->employeeFor($actor, $company);

            if (! $employee instanceof Employee || (int) $employee->getKey() !== (int) $request->employee_id) {
                return;
            }

            if (! $actor->can('leave_request.self_cancel')) {
                return;
            }

            $this->notifyReviewers($request, $company, LeaveRequestWithdrawn::class, 'withdrawn');
        });
    }

    /** @param class-string<LeaveRequestNotification> $notificationClass */
    private function notifyReviewers(LeaveRequest $request, Company $company, string $notificationClass, string $event): void
    {
        $employeeUserId = $request->employee()->value('user_id');
        $recipients = User::query()->whereIn('id', $this->reviewerUserIds($request, $company, $employeeUserId))->orderBy('id');
        $sent = 0;

        $recipients->chunkById(100, function (Collection $users) use ($request, $company, $notificationClass, &$sent): void {
            foreach ($users as $recipient) {
                $recipient->unsetRelation('roles')->unsetRelation('permissions');

                if (! $recipient->can('leave_request.view')
                    || (! $recipient->can('leave_request.approve') && ! $recipient->can('leave_request.reject'))
                    || ! $this->reviewerScope->apply(LeaveRequest::query(), $recipient, $company)->whereKey($request->getKey())->exists()) {
                    continue;
                }

                $recipient->notify(new $notificationClass((int) $request->company_id, (int) $request->getKey()));
                $sent++;
            }
        });

        if ($sent === 0) {
            Log::warning('No currently authorized Leave reviewer received the lifecycle notification.', [
                'event' => $event,
                'company_id' => $request->company_id,
                'leave_request_id' => $request->getKey(),
            ]);
        }
    }

    /** @param class-string<LeaveRequestNotification> $notificationClass */
    private function notifyEmployee(LeaveRequest $request, string $notificationClass, string $event): void
    {
        $company = $this->companyFor($request);
        if (! $company instanceof Company) {
            return;
        }

        $request->loadMissing('employee.user');
        $employee = $request->employee;
        $recipient = $employee?->user;

        if (! $employee instanceof Employee || ! $recipient instanceof User) {
            return;
        }

        $this->withCompanyPermissions((int) $company->getKey(), function () use ($request, $company, $employee, $recipient, $notificationClass, $event): void {
            $resolvedEmployee = $this->employeePortalAccess->employeeFor($recipient, $company);

            if (! $resolvedEmployee instanceof Employee || (int) $resolvedEmployee->getKey() !== (int) $employee->getKey()
                || ! $recipient->can('leave_request.self_view')
                || ($event === 'approved' && $request->status !== LeaveRequestStatus::Approved)
                || ($event === 'rejected' && $request->status !== LeaveRequestStatus::Rejected)) {
                return;
            }

            $recipient->notify(new $notificationClass((int) $request->company_id, (int) $request->getKey()));
        });
    }

    private function companyFor(LeaveRequest $request): ?Company
    {
        return Company::query()->whereKey($request->company_id)->where('status', 'active')->first();
    }

    private function reviewerUserIds(LeaveRequest $request, Company $company, ?int $employeeUserId): QueryBuilder
    {
        $companyId = (int) $company->getKey();
        $memberIds = DB::table('company_users as memberships')
            ->join('companies', 'companies.id', '=', 'memberships.company_id')
            ->where('memberships.company_id', $companyId)
            ->where('memberships.status', 'active')
            ->where('companies.status', 'active')
            ->whereIn('memberships.user_id', $this->userIdsWithAnyPermission($companyId, ['leave_request.view']))
            ->whereIn('memberships.user_id', $this->userIdsWithAnyPermission($companyId, ['leave_request.approve', 'leave_request.reject']))
            ->whereNotIn('memberships.user_id', array_filter([(int) $request->requested_by, (int) $employeeUserId]));

        return $memberIds->select('memberships.user_id')->distinct();
    }

    /** @param list<string> $permissionNames */
    private function userIdsWithAnyPermission(int $companyId, array $permissionNames): QueryBuilder
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $rolesTable = $tables['roles'] ?? 'roles';
        $permissionsTable = $tables['permissions'] ?? 'permissions';
        $modelHasRolesTable = $tables['model_has_roles'] ?? 'model_has_roles';
        $roleHasPermissionsTable = $tables['role_has_permissions'] ?? 'role_has_permissions';
        $modelHasPermissionsTable = $tables['model_has_permissions'] ?? 'model_has_permissions';
        $teamColumn = $columns['team_foreign_key'] ?? 'team_id';

        $roleUserIds = DB::table("{$modelHasRolesTable} as assigned_roles")
            ->select('assigned_roles.model_id as user_id')
            ->join("{$rolesTable} as company_roles", 'company_roles.id', '=', 'assigned_roles.role_id')
            ->join("{$roleHasPermissionsTable} as role_permissions", 'role_permissions.role_id', '=', 'company_roles.id')
            ->join("{$permissionsTable} as permissions", 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('assigned_roles.model_type', (new User)->getMorphClass())
            ->where("assigned_roles.{$teamColumn}", $companyId)
            ->where("company_roles.{$teamColumn}", $companyId)
            ->where('company_roles.guard_name', 'web')
            ->where('permissions.guard_name', 'web')
            ->whereIn('permissions.name', $permissionNames);
        $directUserIds = DB::table("{$modelHasPermissionsTable} as assigned_permissions")
            ->select('assigned_permissions.model_id as user_id')
            ->join("{$permissionsTable} as permissions", 'permissions.id', '=', 'assigned_permissions.permission_id')
            ->where('assigned_permissions.model_type', (new User)->getMorphClass())
            ->where("assigned_permissions.{$teamColumn}", $companyId)
            ->where('permissions.guard_name', 'web')
            ->whereIn('permissions.name', $permissionNames);

        return DB::query()
            ->fromSub($roleUserIds->union($directUserIds), 'permission_users')
            ->select('user_id')
            ->distinct();
    }

    private function withCompanyPermissions(int $companyId, callable $callback): void
    {
        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        $this->permissionRegistrar->setPermissionsTeamId($companyId);

        try {
            $callback();
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
