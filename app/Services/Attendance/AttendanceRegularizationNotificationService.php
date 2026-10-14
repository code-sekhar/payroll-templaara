<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use App\Notifications\AttendanceCorrectionNotification;
use App\Notifications\AttendanceCorrectionRejected;
use App\Notifications\AttendanceCorrectionSubmitted;
use App\Services\Employees\EmployeePortalAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class AttendanceRegularizationNotificationService
{
    public function __construct(private readonly EmployeePortalAccess $employeePortalAccess) {}

    public function correctionSubmitted(AttendanceRegularizationRequest $request): void
    {
        $this->deliverToReviewers($request, AttendanceCorrectionSubmitted::class, 'submitted');
    }

    public function correctionApproved(AttendanceRegularizationRequest $request): void
    {
        $this->deliverToEmployee($request, AttendanceCorrectionApproved::class, 'approved');
    }

    public function correctionRejected(AttendanceRegularizationRequest $request): void
    {
        $this->deliverToEmployee($request, AttendanceCorrectionRejected::class, 'rejected');
    }

    /** @param class-string<AttendanceCorrectionNotification> $notificationClass */
    private function deliverToReviewers(AttendanceRegularizationRequest $request, string $notificationClass, string $event): void
    {
        try {
            $request->loadMissing('employee');
            $employee = $request->employee;
            $company = $request->company()->first();

            if (! $employee || ! $company || (int) $employee->company_id !== (int) $request->company_id) {
                return;
            }

            $recipients = User::query()
                ->whereIn('id', $this->reviewerUserIds($request, $employee, $company))
                ->orderBy('id')
                ->lazyById(100);

            foreach ($recipients as $recipient) {
                $this->notifyOnce($recipient, $request, $employee, $notificationClass, $event);
            }
        } catch (Throwable $exception) {
            $this->logFailure($request, $event, $exception);
        }
    }

    /** @param class-string<AttendanceCorrectionNotification> $notificationClass */
    private function deliverToEmployee(AttendanceRegularizationRequest $request, string $notificationClass, string $event): void
    {
        try {
            $request->loadMissing('employee');
            $employee = $request->employee;
            $company = $request->company()->first();
            $recipient = $employee?->user;

            if (! $employee || ! $company || ! $recipient
                || (int) $employee->company_id !== (int) $request->company_id
                || ! $this->hasEmployeePortalIdentity($recipient, $company, $employee)) {
                return;
            }

            $this->notifyOnce($recipient, $request, $employee, $notificationClass, $event);
        } catch (Throwable $exception) {
            $this->logFailure($request, $event, $exception);
        }
    }

    private function hasEmployeePortalIdentity(User $user, Company $company, Employee $employee): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $this->employeePortalAccess->employeeFor($user, $company)?->is($employee) ?? false;
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function reviewerUserIds(AttendanceRegularizationRequest $request, Employee $employee, Company $company): Builder
    {
        $companyId = (int) $company->getKey();
        $reviewers = DB::table('company_users as memberships')
            ->where('memberships.company_id', $companyId)
            ->where('memberships.status', 'active')
            ->whereIn('memberships.user_id', $this->userIdsWithAnyPermission($companyId, ['attendance_regularization.view']))
            ->whereIn('memberships.user_id', $this->userIdsWithAnyPermission($companyId, ['attendance_regularization.approve', 'attendance_regularization.reject']))
            ->whereNotIn('memberships.user_id', array_filter([(int) $request->requested_by, (int) ($employee->user_id ?? 0)]))
            ->where(function ($query) use ($companyId, $employee): void {
                $query->whereIn('memberships.user_id', $this->userIdsWithAnyPermission($companyId, ['employee.view']))
                    ->orWhere(function ($teamQuery) use ($companyId, $employee): void {
                        $teamQuery->whereIn('memberships.user_id', $this->userIdsWithAnyPermission($companyId, ['employee.view_team']))
                            ->whereExists(function ($employeeQuery) use ($companyId, $employee): void {
                                $employeeQuery->selectRaw('1')
                                    ->from('employees as manager_employees')
                                    ->whereColumn('manager_employees.user_id', 'memberships.user_id')
                                    ->where('manager_employees.company_id', $companyId)
                                    ->where(function ($visibleEmployeeQuery) use ($employee): void {
                                        $visibleEmployeeQuery->where('manager_employees.id', $employee->getKey());
                                        if ($employee->manager_id !== null) {
                                            $visibleEmployeeQuery->orWhere('manager_employees.id', $employee->manager_id);
                                        }
                                    });
                            });
                    });
            });

        return $reviewers->select('memberships.user_id')->distinct();
    }

    /** @param list<string> $permissionNames
     */
    private function userIdsWithAnyPermission(int $companyId, array $permissionNames): Builder
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

    /** @param class-string<AttendanceCorrectionNotification> $notificationClass */
    private function notifyOnce(User $recipient, AttendanceRegularizationRequest $request, Employee $employee, string $notificationClass, string $event): void
    {
        $query = $recipient->notifications()->where('type', $notificationClass);
        $query->where('company_id', $request->company_id);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereRaw("(data::jsonb ->> 'regularization_id') = ?", [(string) $request->getKey()]);
        } else {
            $query->where('data->regularization_id', (int) $request->getKey());
        }

        $alreadySent = $query->exists();

        if ($alreadySent) {
            return;
        }

        try {
            $recipient->notify(new $notificationClass(
                (int) $request->company_id,
                (int) $request->getKey(),
                $request->attendance_date->toDateString(),
                $employee->full_name,
            ));
        } catch (Throwable $exception) {
            $this->logFailure($request, $event, $exception);
        }
    }

    private function logFailure(AttendanceRegularizationRequest $request, string $event, Throwable $exception): void
    {
        Log::warning('Attendance correction notification delivery failed after the workflow transition.', [
            'event' => $event,
            'company_id' => $request->company_id,
            'regularization_id' => $request->getKey(),
            'exception' => $exception::class,
        ]);
    }
}
