<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceNotificationController;
use App\Http\Controllers\AttendancePolicyController;
use App\Http\Controllers\AttendancePunchController;
use App\Http\Controllers\AttendanceRegularizationController;
use App\Http\Controllers\AttendanceWorksiteController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyMemberController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\EmployeeAttendanceCorrectionController;
use App\Http\Controllers\EmployeeAttendanceNotificationController;
use App\Http\Controllers\EmployeeAttendancePolicyAssignmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeHolidayCalendarAssignmentController;
use App\Http\Controllers\EmployeeInvitationController;
use App\Http\Controllers\EmployeeLeaveApplicationController;
use App\Http\Controllers\EmployeeLeaveBalanceController;
use App\Http\Controllers\EmployeeLeaveCalendarController;
use App\Http\Controllers\EmployeeLeaveController;
use App\Http\Controllers\EmployeeLeavePolicyAssignmentController;
use App\Http\Controllers\EmployeeLeaveRequestController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\EmployeeProfileController;
use App\Http\Controllers\EmployeePunchController;
use App\Http\Controllers\EmployeeShiftAssignmentController;
use App\Http\Controllers\EmployeeWorkScheduleAssignmentController;
use App\Http\Controllers\HolidayCalendarController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveApprovalDecisionController;
use App\Http\Controllers\LeaveApprovalWorkspaceController;
use App\Http\Controllers\LeavePolicyController;
use App\Http\Controllers\LeaveReportController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTeamCalendarController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\MyLeaveController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\WorkScheduleController;
use App\Http\Middleware\RedirectEmployeeOnlyFromDashboard;
use App\Http\Middleware\RequireActiveCompany;
use App\Http\Middleware\RequireEmployeePortalIdentity;
use App\Http\Middleware\SetCompanyContext;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('employee-invitations/{token}', [EmployeeInvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('employee-invitations.show');

Route::post('employee-invitations/{token}/accept', [EmployeeInvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('employee-invitations.accept');

Route::middleware(['auth', SetCompanyContext::class])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->middleware(RedirectEmployeeOnlyFromDashboard::class)->name('dashboard');

    Route::middleware(RequireActiveCompany::class)->prefix('notifications')->as('notifications.')->group(function (): void {
        Route::get('/', [NotificationCenterController::class, 'index'])->name('index');
        Route::post('/read-all', [NotificationCenterController::class, 'readAll'])->name('read-all');
        Route::post('/{notification}/read', [NotificationCenterController::class, 'read'])->whereUuid('notification')->name('read');
    });

    Route::middleware(RequireActiveCompany::class)->prefix('leave/approvals')->as('leave.approvals.')->group(function (): void {
        Route::get('/', [LeaveApprovalWorkspaceController::class, 'index'])->name('index');
        Route::get('/{requestId}', [LeaveApprovalWorkspaceController::class, 'show'])->whereNumber('requestId')->name('show');
    });
    Route::get('leave/calendar', LeaveTeamCalendarController::class)
        ->middleware(RequireActiveCompany::class)
        ->name('leave.calendar.index');

    Route::get('employee', [EmployeePortalController::class, 'index'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.portal');
    Route::get('employee/profile', [EmployeeProfileController::class, 'show'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.profile.show');
    Route::get('employee/leave', [EmployeeLeaveController::class, 'index'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.index');
    Route::get('employee/leave/calendar', EmployeeLeaveCalendarController::class)
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.calendar');
    Route::get('employee/leave/requests', [EmployeeLeaveRequestController::class, 'index'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.requests.index');
    Route::get('employee/leave/requests/{requestId}', [EmployeeLeaveRequestController::class, 'show'])
        ->whereNumber('requestId')
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.requests.show');
    Route::post('employee/leave/requests/{requestId}/cancel', [EmployeeLeaveRequestController::class, 'cancel'])
        ->whereNumber('requestId')
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.requests.cancel');
    Route::get('employee/leave/apply', [EmployeeLeaveApplicationController::class, 'create'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.apply');
    Route::post('employee/leave', [EmployeeLeaveApplicationController::class, 'store'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.leave.store');
    Route::get('employee/attendance', [EmployeeAttendanceController::class, 'index'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.attendance.index');
    Route::get('employee/notifications', [EmployeeAttendanceNotificationController::class, 'index'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.notifications.index');
    Route::post('employee/notifications/{notification}/open', [EmployeeAttendanceNotificationController::class, 'open'])
        ->whereUuid('notification')
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.notifications.open');
    Route::post('employee/notifications/read-all', [EmployeeAttendanceNotificationController::class, 'readAll'])
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.notifications.read-all');
    Route::post('employee/notifications/{notification}/read', [EmployeeAttendanceNotificationController::class, 'read'])
        ->whereUuid('notification')
        ->middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])
        ->name('employee.notifications.read');
    Route::middleware([RequireActiveCompany::class, RequireEmployeePortalIdentity::class])->prefix('employee/attendance')->as('employee.attendance.')->group(function (): void {
        Route::get('/corrections/create', [EmployeeAttendanceCorrectionController::class, 'create'])->name('corrections.create');
        Route::post('/corrections', [EmployeeAttendanceCorrectionController::class, 'store'])->name('corrections.store');
        Route::post('/corrections/{attendanceDate}/{requestReference}/cancel', [EmployeeAttendanceCorrectionController::class, 'cancel'])
            ->where('attendanceDate', '\\d{4}-\\d{2}-\\d{2}')
            ->where('requestReference', '[a-f0-9]{64}')
            ->name('corrections.cancel');
        Route::get('/punch-state', [EmployeePunchController::class, 'state'])->name('punch-state');
        Route::post('/punch-in', [EmployeePunchController::class, 'punchIn'])->name('punch-in');
        Route::post('/punch-out', [EmployeePunchController::class, 'punchOut'])->name('punch-out');
    });

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::put('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::get('companies/{company}/members', [CompanyController::class, 'members'])->name('companies.members');
    Route::post('companies/{company}/members', [CompanyMemberController::class, 'store'])->name('companies.members.store');
    Route::patch('companies/{company}/members/{user}', [CompanyMemberController::class, 'update'])->name('companies.members.update');
    Route::get('companies/{company}/roles', [CompanyController::class, 'roles'])->name('companies.roles');

    Route::scopeBindings()->prefix('companies/{company}/shifts')->as('companies.shifts.')->group(function (): void {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::get('/create', [ShiftController::class, 'create'])->name('create');
        Route::post('/', [ShiftController::class, 'store'])->name('store');
        Route::get('/{shift}/edit', [ShiftController::class, 'edit'])->name('edit');
        Route::put('/{shift}', [ShiftController::class, 'update'])->name('update');
        Route::patch('/{shift}/activate', [ShiftController::class, 'activate'])->name('activate');
        Route::patch('/{shift}/deactivate', [ShiftController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{shift}', [ShiftController::class, 'destroy'])->name('destroy');
    });

    Route::scopeBindings()->prefix('companies/{company}/shift-assignments')->as('companies.shift-assignments.')->group(function (): void {
        Route::get('/', [EmployeeShiftAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeShiftAssignmentController::class, 'create'])->name('create');
        Route::post('/', [EmployeeShiftAssignmentController::class, 'store'])->name('store');
        Route::get('/{employeeShiftAssignment}/edit', [EmployeeShiftAssignmentController::class, 'edit'])->name('edit');
        Route::put('/{employeeShiftAssignment}', [EmployeeShiftAssignmentController::class, 'update'])->name('update');
        Route::delete('/{employeeShiftAssignment}', [EmployeeShiftAssignmentController::class, 'destroy'])->name('destroy');
    });

    Route::scopeBindings()->prefix('companies/{company}/work-schedules')->as('companies.work-schedules.')->group(function (): void {
        Route::get('/', [WorkScheduleController::class, 'index'])->name('index');
        Route::get('/create', [WorkScheduleController::class, 'create'])->name('create');
        Route::post('/', [WorkScheduleController::class, 'store'])->name('store');
        Route::get('/{workSchedule}/edit', [WorkScheduleController::class, 'edit'])->name('edit');
        Route::put('/{workSchedule}', [WorkScheduleController::class, 'update'])->name('update');
        Route::patch('/{workSchedule}/activate', [WorkScheduleController::class, 'activate'])->name('activate');
        Route::patch('/{workSchedule}/deactivate', [WorkScheduleController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{workSchedule}', [WorkScheduleController::class, 'destroy'])->name('destroy');
    });

    Route::scopeBindings()->prefix('companies/{company}/holiday-calendars')->as('companies.holiday-calendars.')->group(function (): void {
        Route::get('/', [HolidayCalendarController::class, 'index'])->name('index');
        Route::get('/create', [HolidayCalendarController::class, 'create'])->name('create');
        Route::post('/', [HolidayCalendarController::class, 'store'])->name('store');
        Route::get('/{holidayCalendar}', [HolidayCalendarController::class, 'show'])->name('show');
        Route::get('/{holidayCalendar}/edit', [HolidayCalendarController::class, 'edit'])->name('edit');
        Route::put('/{holidayCalendar}', [HolidayCalendarController::class, 'update'])->name('update');
        Route::patch('/{holidayCalendar}/activate', [HolidayCalendarController::class, 'activate'])->name('activate');
        Route::patch('/{holidayCalendar}/deactivate', [HolidayCalendarController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{holidayCalendar}', [HolidayCalendarController::class, 'destroy'])->name('destroy');

        Route::scopeBindings()->prefix('/{holidayCalendar}/holidays')->as('holidays.')->group(function (): void {
            Route::get('/create', [HolidayController::class, 'create'])->name('create');
            Route::post('/', [HolidayController::class, 'store'])->name('store');
            Route::get('/{holiday}/edit', [HolidayController::class, 'edit'])->name('edit');
            Route::put('/{holiday}', [HolidayController::class, 'update'])->name('update');
            Route::delete('/{holiday}', [HolidayController::class, 'destroy'])->name('destroy');
        });
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/leave-types')->as('companies.leave-types.')->group(function (): void {
        Route::get('/', [LeaveTypeController::class, 'index'])->name('index');
        Route::get('/create', [LeaveTypeController::class, 'create'])->name('create');
        Route::post('/', [LeaveTypeController::class, 'store'])->name('store');
        Route::get('/{leaveType}/edit', [LeaveTypeController::class, 'edit'])->name('edit');
        Route::put('/{leaveType}', [LeaveTypeController::class, 'update'])->name('update');
        Route::patch('/{leaveType}/activate', [LeaveTypeController::class, 'activate'])->name('activate');
        Route::patch('/{leaveType}/deactivate', [LeaveTypeController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/leave-policies')->as('companies.leave-policies.')->group(function (): void {
        Route::get('/', [LeavePolicyController::class, 'index'])->name('index');
        Route::get('/create', [LeavePolicyController::class, 'create'])->name('create');
        Route::post('/', [LeavePolicyController::class, 'store'])->name('store');
        Route::get('/{leavePolicy}/edit', [LeavePolicyController::class, 'edit'])->name('edit');
        Route::put('/{leavePolicy}', [LeavePolicyController::class, 'update'])->name('update');
        Route::patch('/{leavePolicy}/activate', [LeavePolicyController::class, 'activate'])->name('activate');
        Route::patch('/{leavePolicy}/deactivate', [LeavePolicyController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{leavePolicy}', [LeavePolicyController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/leave-policy-assignments')->as('companies.leave-policy-assignments.')->group(function (): void {
        Route::get('/', [EmployeeLeavePolicyAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeLeavePolicyAssignmentController::class, 'create'])->name('create');
        Route::post('/', [EmployeeLeavePolicyAssignmentController::class, 'store'])->name('store');
        Route::get('/{employeeLeavePolicyAssignment}/edit', [EmployeeLeavePolicyAssignmentController::class, 'edit'])->name('edit');
        Route::put('/{employeeLeavePolicyAssignment}', [EmployeeLeavePolicyAssignmentController::class, 'update'])->name('update');
        Route::delete('/{employeeLeavePolicyAssignment}', [EmployeeLeavePolicyAssignmentController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/leave-balances')->as('companies.leave-balances.')->group(function (): void {
        Route::get('/', [EmployeeLeaveBalanceController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeLeaveBalanceController::class, 'create'])->name('create');
        Route::post('/', [EmployeeLeaveBalanceController::class, 'store'])->name('store');
        Route::post('/{leaveBalanceAccount}/opening', [EmployeeLeaveBalanceController::class, 'postOpening'])->name('opening');
        Route::post('/{leaveBalanceAccount}/adjustments', [EmployeeLeaveBalanceController::class, 'postAdjustment'])->name('adjustments');
        Route::post('/{leaveBalanceAccount}/close', [EmployeeLeaveBalanceController::class, 'close'])->name('close');
        Route::get('/{leaveBalanceAccount}', [EmployeeLeaveBalanceController::class, 'show'])->name('show');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/leave-requests')->as('companies.leave-requests.')->group(function (): void {
        Route::get('/', [LeaveRequestController::class, 'index'])->name('index');
        Route::get('/create', [LeaveRequestController::class, 'create'])->name('create');
        Route::post('/', [LeaveRequestController::class, 'store'])->name('store');
        Route::post('/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('cancel');
        Route::post('/{leaveRequest}/cancel-approved', [LeaveRequestController::class, 'cancelApproved'])->name('cancel-approved');
        Route::post('/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->name('approve');
        Route::post('/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->name('reject');
        Route::post('/{leaveRequest}/approve-from-inbox', [LeaveApprovalDecisionController::class, 'approve'])->name('approve-from-inbox');
        Route::post('/{leaveRequest}/reject-from-inbox', [LeaveApprovalDecisionController::class, 'reject'])->name('reject-from-inbox');
        Route::get('/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('show');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/leave-reports')->as('companies.leave-reports.')->group(function (): void {
        Route::get('/export', [LeaveReportController::class, 'export'])->name('export');
        Route::get('/', [LeaveReportController::class, 'index'])->name('index');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/my-leave')->as('companies.my-leave.')->group(function (): void {
        Route::get('/', [MyLeaveController::class, 'index'])->name('index');
        Route::get('/create', [MyLeaveController::class, 'create'])->name('create');
        Route::post('/', [MyLeaveController::class, 'store'])->name('store');
        Route::post('/{leaveRequest}/cancel', [MyLeaveController::class, 'cancel'])->name('cancel');
        Route::get('/{leaveRequest}', [MyLeaveController::class, 'show'])->name('show');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/attendance-policies')->as('companies.attendance-policies.')->group(function (): void {
        Route::get('/', [AttendancePolicyController::class, 'index'])->name('index');
        Route::get('/create', [AttendancePolicyController::class, 'create'])->name('create');
        Route::post('/', [AttendancePolicyController::class, 'store'])->name('store');
        Route::get('/{attendancePolicy}/edit', [AttendancePolicyController::class, 'edit'])->name('edit');
        Route::put('/{attendancePolicy}', [AttendancePolicyController::class, 'update'])->name('update');
        Route::patch('/{attendancePolicy}/activate', [AttendancePolicyController::class, 'activate'])->name('activate');
        Route::patch('/{attendancePolicy}/deactivate', [AttendancePolicyController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{attendancePolicy}', [AttendancePolicyController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/attendance-worksites')->as('companies.attendance-worksites.')->group(function (): void {
        Route::get('/', [AttendanceWorksiteController::class, 'index'])->name('index');
        Route::get('/create', [AttendanceWorksiteController::class, 'create'])->name('create');
        Route::post('/', [AttendanceWorksiteController::class, 'store'])->name('store');
        Route::get('/{attendanceWorksite}/edit', [AttendanceWorksiteController::class, 'edit'])->name('edit');
        Route::put('/{attendanceWorksite}', [AttendanceWorksiteController::class, 'update'])->name('update');
        Route::patch('/{attendanceWorksite}/activate', [AttendanceWorksiteController::class, 'activate'])->name('activate');
        Route::patch('/{attendanceWorksite}/deactivate', [AttendanceWorksiteController::class, 'deactivate'])->name('deactivate');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/attendance-policy-assignments')->as('companies.attendance-policy-assignments.')->group(function (): void {
        Route::get('/', [EmployeeAttendancePolicyAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeAttendancePolicyAssignmentController::class, 'create'])->name('create');
        Route::post('/', [EmployeeAttendancePolicyAssignmentController::class, 'store'])->name('store');
        Route::get('/{attendancePolicyAssignment}/edit', [EmployeeAttendancePolicyAssignmentController::class, 'edit'])->name('edit');
        Route::put('/{attendancePolicyAssignment}', [EmployeeAttendancePolicyAssignmentController::class, 'update'])->name('update');
        Route::delete('/{attendancePolicyAssignment}', [EmployeeAttendancePolicyAssignmentController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->prefix('companies/{company}/attendance-punches')->as('companies.attendance-punches.')->group(function (): void {
        Route::get('/', [AttendancePunchController::class, 'index'])->name('index');
        Route::get('/manual/create', [AttendancePunchController::class, 'createManual'])->name('manual.create');
        Route::post('/manual', [AttendancePunchController::class, 'storeManual'])->name('manual.store');
        Route::post('/self', [AttendancePunchController::class, 'selfPunch'])
            ->middleware(RequireEmployeePortalIdentity::class)
            ->name('self');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/attendance')->as('companies.attendance.')->group(function (): void {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('/{dailyAttendanceRecord}', [AttendanceController::class, 'show'])->name('show');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/my-attendance')->as('companies.my-attendance.')->group(function (): void {
        Route::get('/', [AttendanceController::class, 'myAttendance'])->name('index');
        Route::get('/{dailyAttendanceRecord}', [AttendanceController::class, 'showMine'])->name('show');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/attendance-regularizations')->as('companies.attendance-regularizations.')->group(function (): void {
        Route::get('/', [AttendanceRegularizationController::class, 'index'])->name('index');
        Route::get('/create', [AttendanceRegularizationController::class, 'create'])->name('create');
        Route::post('/', [AttendanceRegularizationController::class, 'store'])->name('store');
        Route::get('/self/create', [AttendanceRegularizationController::class, 'createSelf'])->name('self.create');
        Route::post('/self', [AttendanceRegularizationController::class, 'storeSelf'])->name('self.store');
        Route::post('/{attendanceRegularizationRequest}/approve', [AttendanceRegularizationController::class, 'approve'])->name('approve');
        Route::post('/{attendanceRegularizationRequest}/reject', [AttendanceRegularizationController::class, 'reject'])->name('reject');
        Route::get('/{attendanceRegularizationRequest}', [AttendanceRegularizationController::class, 'show'])->name('show');
        Route::post('/{attendanceRegularizationRequest}/cancel', [AttendanceRegularizationController::class, 'cancel'])->name('cancel');
    });

    Route::middleware(RequireActiveCompany::class)->prefix('companies/{company}/attendance-notifications')->as('companies.notifications.')->group(function (): void {
        Route::get('/', [AttendanceNotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/open', [AttendanceNotificationController::class, 'open'])->whereUuid('notification')->name('open');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/work-schedule-assignments')->as('companies.work-schedule-assignments.')->group(function (): void {
        Route::get('/', [EmployeeWorkScheduleAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeWorkScheduleAssignmentController::class, 'create'])->name('create');
        Route::post('/', [EmployeeWorkScheduleAssignmentController::class, 'store'])->name('store');
        Route::get('/{employeeWorkScheduleAssignment}/edit', [EmployeeWorkScheduleAssignmentController::class, 'edit'])->name('edit');
        Route::put('/{employeeWorkScheduleAssignment}', [EmployeeWorkScheduleAssignmentController::class, 'update'])->name('update');
        Route::delete('/{employeeWorkScheduleAssignment}', [EmployeeWorkScheduleAssignmentController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()->prefix('companies/{company}/employee-holiday-calendar-assignments')->as('companies.employee-holiday-calendar-assignments.')->group(function (): void {
        Route::get('/', [EmployeeHolidayCalendarAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeHolidayCalendarAssignmentController::class, 'create'])->name('create');
        Route::post('/', [EmployeeHolidayCalendarAssignmentController::class, 'store'])->name('store');
        Route::get('/{holidayCalendarAssignment}/edit', [EmployeeHolidayCalendarAssignmentController::class, 'edit'])->name('edit');
        Route::put('/{holidayCalendarAssignment}', [EmployeeHolidayCalendarAssignmentController::class, 'update'])->name('update');
        Route::delete('/{holidayCalendarAssignment}', [EmployeeHolidayCalendarAssignmentController::class, 'destroy'])->name('destroy');
    });

    Route::middleware(RequireActiveCompany::class)->group(function (): void {
        foreach ([['branches', 'branch', BranchController::class], ['departments', 'department', DepartmentController::class], ['designations', 'designation', DesignationController::class]] as [$path, $parameter, $controller]) {
            Route::get($path, [$controller, 'index'])->name($path.'.index');
            Route::get($path.'/create', [$controller, 'create'])->name($path.'.create');
            Route::post($path, [$controller, 'store'])->name($path.'.store');
            Route::get($path.'/{'.$parameter.'}', [$controller, 'edit'])->name($path.'.edit');
            Route::put($path.'/{'.$parameter.'}', [$controller, 'update'])->name($path.'.update');
        }

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    });

    Route::middleware(RequireActiveCompany::class)->scopeBindings()
        ->prefix('companies/{company}/employees')->as('companies.employees.')
        ->post('/{employee}/invitation', [EmployeeController::class, 'invite'])->name('invitation.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
