<?php

namespace App\Actions;

use App\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SeedCompanyRoles
{
    /** @var array<string, list<string>> */
    private const EMPLOYEE_INVITATION_PERMISSIONS = [
        'company_owner' => ['employee.login.invite'],
        'hr_admin' => ['employee.login.invite'],
        'hr_manager' => ['employee.login.invite'],
    ];

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'company_owner' => ['dashboard.view', 'company.view', 'company.update', 'company.settings', 'user.view', 'user.create', 'user.update', 'user.delete', 'user.assign_role', 'role.view', 'role.create', 'role.update', 'role.delete', 'employee.view', 'employee.create', 'employee.update', 'employee.delete', 'branch.view', 'branch.create', 'branch.update', 'branch.delete', 'department.view', 'department.create', 'department.update', 'department.delete', 'designation.view', 'designation.create', 'designation.update', 'designation.delete', 'shift.view', 'shift.create', 'shift.update', 'shift.delete', 'shift.activate', 'shift.deactivate', 'shift_assignment.view', 'shift_assignment.create', 'shift_assignment.update', 'shift_assignment.delete', 'work_schedule.view', 'work_schedule.create', 'work_schedule.update', 'work_schedule.delete', 'work_schedule.activate', 'work_schedule.deactivate', 'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete', 'leave.view', 'leave.create', 'leave.update', 'leave.approve', 'leave.reject', 'payroll.view', 'payroll.create', 'payroll.update', 'payroll.process', 'payroll.delete', 'report.view', 'report.export'],
        'hr_admin' => ['dashboard.view', 'company.view', 'user.view', 'employee.view', 'employee.create', 'employee.update', 'employee.delete', 'branch.view', 'branch.create', 'branch.update', 'branch.delete', 'department.view', 'department.create', 'department.update', 'department.delete', 'designation.view', 'designation.create', 'designation.update', 'designation.delete', 'shift.view', 'shift.create', 'shift.update', 'shift.delete', 'shift.activate', 'shift.deactivate', 'shift_assignment.view', 'shift_assignment.create', 'shift_assignment.update', 'shift_assignment.delete', 'work_schedule.view', 'work_schedule.create', 'work_schedule.update', 'work_schedule.delete', 'work_schedule.activate', 'work_schedule.deactivate', 'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete', 'leave.view', 'leave.create', 'leave.update', 'leave.approve', 'leave.reject', 'report.view', 'report.export'],
        'hr_manager' => ['dashboard.view', 'company.view', 'employee.view', 'employee.create', 'employee.update', 'branch.view', 'department.view', 'designation.view', 'shift.view', 'shift.create', 'shift.update', 'shift_assignment.view', 'shift_assignment.create', 'shift_assignment.update', 'work_schedule.view', 'work_schedule.create', 'work_schedule.update', 'attendance.view', 'attendance.create', 'attendance.update', 'leave.view', 'leave.create', 'leave.update', 'leave.approve', 'leave.reject', 'report.view'],
        'manager' => ['dashboard.view', 'employee.view_team', 'shift.view', 'shift_assignment.view', 'work_schedule.view', 'attendance.view_team', 'leave.view_team', 'leave.approve_team', 'leave.reject_team'],
        'accountant' => ['dashboard.view', 'payroll.view', 'payroll.create', 'payroll.update', 'payroll.process', 'payroll.delete', 'report.view', 'report.export'],
        'employee' => ['dashboard.view', 'employee.view_own', 'attendance.view_own', 'leave.view_own', 'leave.create', 'leave.balance.view_own', 'payroll.payslip.view_own'],
    ];

    /** @var array<string, list<string>> */
    private const WORK_SCHEDULE_ASSIGNMENT_PERMISSIONS = [
        'company_owner' => ['work_schedule_assignment.view', 'work_schedule_assignment.create', 'work_schedule_assignment.update', 'work_schedule_assignment.delete'],
        'hr_admin' => ['work_schedule_assignment.view', 'work_schedule_assignment.create', 'work_schedule_assignment.update', 'work_schedule_assignment.delete'],
        'hr_manager' => ['work_schedule_assignment.view', 'work_schedule_assignment.create', 'work_schedule_assignment.update'],
        'manager' => ['work_schedule_assignment.view'],
    ];

    /** @var array<string, list<string>> */
    private const HOLIDAY_PERMISSIONS = [
        'company_owner' => ['holiday_calendar.view', 'holiday_calendar.create', 'holiday_calendar.update', 'holiday_calendar.delete', 'holiday_calendar.activate', 'holiday_calendar.deactivate', 'holiday.view', 'holiday.create', 'holiday.update', 'holiday.delete'],
        'hr_admin' => ['holiday_calendar.view', 'holiday_calendar.create', 'holiday_calendar.update', 'holiday_calendar.delete', 'holiday_calendar.activate', 'holiday_calendar.deactivate', 'holiday.view', 'holiday.create', 'holiday.update', 'holiday.delete'],
        'hr_manager' => ['holiday_calendar.view', 'holiday_calendar.create', 'holiday_calendar.update', 'holiday.view', 'holiday.create', 'holiday.update'],
        'manager' => ['holiday_calendar.view', 'holiday.view'],
    ];

    /** @var array<string, list<string>> */
    private const HOLIDAY_CALENDAR_ASSIGNMENT_PERMISSIONS = [
        'company_owner' => ['holiday_calendar_assignment.view', 'holiday_calendar_assignment.create', 'holiday_calendar_assignment.update', 'holiday_calendar_assignment.delete'],
        'hr_admin' => ['holiday_calendar_assignment.view', 'holiday_calendar_assignment.create', 'holiday_calendar_assignment.update', 'holiday_calendar_assignment.delete'],
        'hr_manager' => ['holiday_calendar_assignment.view', 'holiday_calendar_assignment.create', 'holiday_calendar_assignment.update'],
        'manager' => ['holiday_calendar_assignment.view'],
    ];

    /** @var array<string, list<string>> */
    private const ATTENDANCE_POLICY_PERMISSIONS = [
        'company_owner' => ['attendance_policy.view', 'attendance_policy.create', 'attendance_policy.update', 'attendance_policy.delete', 'attendance_policy.activate', 'attendance_policy.deactivate'],
        'hr_admin' => ['attendance_policy.view', 'attendance_policy.create', 'attendance_policy.update', 'attendance_policy.delete', 'attendance_policy.activate', 'attendance_policy.deactivate'],
        'hr_manager' => ['attendance_policy.view', 'attendance_policy.create', 'attendance_policy.update'],
        'manager' => ['attendance_policy.view'],
    ];

    /** @var array<string, list<string>> */
    private const ATTENDANCE_WORKSITE_PERMISSIONS = [
        'company_owner' => ['attendance_worksite.view', 'attendance_worksite.create', 'attendance_worksite.update', 'attendance_worksite.activate', 'attendance_worksite.deactivate'],
        'hr_admin' => ['attendance_worksite.view', 'attendance_worksite.create', 'attendance_worksite.update', 'attendance_worksite.activate', 'attendance_worksite.deactivate'],
        'hr_manager' => ['attendance_worksite.view', 'attendance_worksite.create', 'attendance_worksite.update'],
        'manager' => ['attendance_worksite.view'],
    ];

    /** @var array<string, list<string>> */
    private const ATTENDANCE_POLICY_ASSIGNMENT_PERMISSIONS = [
        'company_owner' => ['attendance_policy_assignment.view', 'attendance_policy_assignment.create', 'attendance_policy_assignment.update', 'attendance_policy_assignment.delete'],
        'hr_admin' => ['attendance_policy_assignment.view', 'attendance_policy_assignment.create', 'attendance_policy_assignment.update', 'attendance_policy_assignment.delete'],
        'hr_manager' => ['attendance_policy_assignment.view', 'attendance_policy_assignment.create', 'attendance_policy_assignment.update'],
        'manager' => ['attendance_policy_assignment.view'],
    ];

    /** @var array<string, list<string>> */
    private const ATTENDANCE_PUNCH_PERMISSIONS = [
        'company_owner' => ['attendance_punch.view', 'attendance_punch.create_manual'],
        'hr_admin' => ['attendance_punch.view', 'attendance_punch.create_manual'],
        'hr_manager' => ['attendance_punch.view', 'attendance_punch.create_manual'],
        'manager' => ['attendance_punch.view'],
        'employee' => ['attendance_punch.self_punch'],
    ];

    /** @var array<string, list<string>> */
    private const ATTENDANCE_REPORT_PERMISSIONS = [
        'company_owner' => ['attendance.view_details'],
        'hr_admin' => ['attendance.view_details'],
        'hr_manager' => ['attendance.view_details'],
        'manager' => ['attendance.view_details'],
        'employee' => ['attendance.self_view'],
    ];

    /** @var array<string, list<string>> */
    private const ATTENDANCE_REGULARIZATION_PERMISSIONS = [
        'company_owner' => ['attendance_regularization.view', 'attendance_regularization.create', 'attendance_regularization.cancel', 'attendance_regularization.approve', 'attendance_regularization.reject'],
        'hr_admin' => ['attendance_regularization.view', 'attendance_regularization.create', 'attendance_regularization.cancel', 'attendance_regularization.approve', 'attendance_regularization.reject'],
        'hr_manager' => ['attendance_regularization.view', 'attendance_regularization.create', 'attendance_regularization.cancel', 'attendance_regularization.approve', 'attendance_regularization.reject'],
        'manager' => ['attendance_regularization.view'],
        'employee' => ['attendance_regularization.self_view', 'attendance_regularization.self_create', 'attendance_regularization.self_cancel'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_TYPE_PERMISSIONS = [
        'company_owner' => ['leave_type.view', 'leave_type.create', 'leave_type.update', 'leave_type.delete', 'leave_type.activate', 'leave_type.deactivate'],
        'hr_admin' => ['leave_type.view', 'leave_type.create', 'leave_type.update', 'leave_type.delete', 'leave_type.activate', 'leave_type.deactivate'],
        'hr_manager' => ['leave_type.view', 'leave_type.create', 'leave_type.update'],
        'manager' => ['leave_type.view'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_POLICY_PERMISSIONS = [
        'company_owner' => ['leave_policy.view', 'leave_policy.create', 'leave_policy.update', 'leave_policy.delete', 'leave_policy.activate', 'leave_policy.deactivate'],
        'hr_admin' => ['leave_policy.view', 'leave_policy.create', 'leave_policy.update', 'leave_policy.delete', 'leave_policy.activate', 'leave_policy.deactivate'],
        'hr_manager' => ['leave_policy.view', 'leave_policy.create', 'leave_policy.update'],
        'manager' => ['leave_policy.view'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_POLICY_ASSIGNMENT_PERMISSIONS = [
        'company_owner' => ['leave_policy_assignment.view', 'leave_policy_assignment.create', 'leave_policy_assignment.update', 'leave_policy_assignment.delete'],
        'hr_admin' => ['leave_policy_assignment.view', 'leave_policy_assignment.create', 'leave_policy_assignment.update', 'leave_policy_assignment.delete'],
        'hr_manager' => ['leave_policy_assignment.view', 'leave_policy_assignment.create', 'leave_policy_assignment.update'],
        'manager' => ['leave_policy_assignment.view'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_BALANCE_PERMISSIONS = [
        'company_owner' => ['leave_balance.view', 'leave_balance.create', 'leave_balance.adjust', 'leave_balance.close'],
        'hr_admin' => ['leave_balance.view', 'leave_balance.create', 'leave_balance.adjust', 'leave_balance.close'],
        'hr_manager' => ['leave_balance.view', 'leave_balance.create', 'leave_balance.adjust'],
        'manager' => ['leave_balance.view'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_REQUEST_PERMISSIONS = [
        'company_owner' => ['leave_request.view', 'leave_request.create', 'leave_request.cancel'],
        'hr_admin' => ['leave_request.view', 'leave_request.create', 'leave_request.cancel'],
        'hr_manager' => ['leave_request.view', 'leave_request.create', 'leave_request.cancel'],
        'manager' => ['leave_request.view'],
        'employee' => ['leave_request.self_view', 'leave_request.self_create', 'leave_request.self_cancel'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_REQUEST_REVIEW_PERMISSIONS = [
        'company_owner' => ['leave_request.approve', 'leave_request.reject'],
        'hr_admin' => ['leave_request.approve', 'leave_request.reject'],
        'hr_manager' => ['leave_request.approve', 'leave_request.reject'],
    ];

    /** @var array<string, list<string>> */
    private const LEAVE_REPORT_PERMISSIONS = [
        'company_owner' => ['leave_report.view'],
        'hr_admin' => ['leave_report.view'],
        'hr_manager' => ['leave_report.view'],
    ];

    public function handle(Company $company): void
    {
        foreach (self::allPermissionNames() as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);

        foreach (self::ROLE_PERMISSIONS as $name => $permissionNames) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'team_id' => $company->id]);
            $permissionNames = array_merge($permissionNames, self::WORK_SCHEDULE_ASSIGNMENT_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::HOLIDAY_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::HOLIDAY_CALENDAR_ASSIGNMENT_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::ATTENDANCE_POLICY_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::ATTENDANCE_WORKSITE_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::ATTENDANCE_POLICY_ASSIGNMENT_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::ATTENDANCE_PUNCH_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::ATTENDANCE_REPORT_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::ATTENDANCE_REGULARIZATION_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_TYPE_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_POLICY_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_POLICY_ASSIGNMENT_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_BALANCE_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_REQUEST_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_REQUEST_REVIEW_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::LEAVE_REPORT_PERMISSIONS[$name] ?? []);
            $permissionNames = array_merge($permissionNames, self::EMPLOYEE_INVITATION_PERMISSIONS[$name] ?? []);
            $role->syncPermissions(Permission::whereIn('name', $permissionNames)->where('guard_name', 'web')->get());
        }

        $registrar->setPermissionsTeamId($previousTeamId);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    public static function allPermissionNames(): array
    {
        return array_values(array_unique(array_merge(
            ['company.create', 'company.delete', 'system.admin', 'system.user.manage', 'system.role.manage'],
            ...array_values(self::ROLE_PERMISSIONS),
            ...array_values(self::WORK_SCHEDULE_ASSIGNMENT_PERMISSIONS),
            ...array_values(self::HOLIDAY_PERMISSIONS),
            ...array_values(self::HOLIDAY_CALENDAR_ASSIGNMENT_PERMISSIONS),
            ...array_values(self::ATTENDANCE_POLICY_PERMISSIONS),
            ...array_values(self::ATTENDANCE_WORKSITE_PERMISSIONS),
            ...array_values(self::ATTENDANCE_POLICY_ASSIGNMENT_PERMISSIONS),
            ...array_values(self::ATTENDANCE_PUNCH_PERMISSIONS),
            ...array_values(self::ATTENDANCE_REPORT_PERMISSIONS),
            ...array_values(self::ATTENDANCE_REGULARIZATION_PERMISSIONS),
            ...array_values(self::LEAVE_TYPE_PERMISSIONS),
            ...array_values(self::LEAVE_POLICY_PERMISSIONS),
            ...array_values(self::LEAVE_POLICY_ASSIGNMENT_PERMISSIONS),
            ...array_values(self::LEAVE_BALANCE_PERMISSIONS),
            ...array_values(self::LEAVE_REQUEST_PERMISSIONS),
            ...array_values(self::LEAVE_REQUEST_REVIEW_PERMISSIONS),
            ...array_values(self::LEAVE_REPORT_PERMISSIONS),
            ...array_values(self::EMPLOYEE_INVITATION_PERMISSIONS),
        )));
    }
}
