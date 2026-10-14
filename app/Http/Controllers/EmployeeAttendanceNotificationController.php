<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationCenterQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class EmployeeAttendanceNotificationController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, NotificationCenterQuery $notifications): InertiaResponse
    {
        [$company, $employee] = $this->portalContext($request);
        $filter = $request->validate(['filter' => ['sometimes', 'string', Rule::in(['all', 'unread'])]])['filter'] ?? 'all';

        return Inertia::render('employee-portal/notifications', [
            'notificationCenter' => $notifications->page($request->user(), $company, $filter, employeePortal: true)->toArray(),
            'employeePortal' => [
                'companyName' => $company->name,
                'employeeName' => $employee->full_name,
                'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            ],
            'routes' => [
                'readAll' => route('employee.notifications.read-all'),
                'all' => route('employee.notifications.index', ['filter' => 'all']),
                'unread' => route('employee.notifications.index', ['filter' => 'unread']),
            ],
        ]);
    }

    public function read(Request $request, NotificationCenterQuery $notifications, string $notification): RedirectResponse
    {
        [$company] = $this->portalContext($request);
        $notifications->markAsRead($request->user(), $company, $notification);

        return back()->with('success', 'Notification marked as read.');
    }

    public function open(Request $request, NotificationCenterQuery $notifications, string $notification): RedirectResponse
    {
        [$company] = $this->portalContext($request);
        $destination = $notifications->markAsRead($request->user(), $company, $notification);

        return redirect($destination);
    }

    public function readAll(Request $request, NotificationCenterQuery $notifications): RedirectResponse
    {
        [$company] = $this->portalContext($request);
        $marked = $notifications->markAllAsRead($request->user(), $company);

        return back()->with('success', $marked === 0 ? 'There are no unread notifications to update.' : 'Authorized notifications marked as read.');
    }

    /** @return array{Company, Employee} */
    private function portalContext(Request $request): array
    {
        $company = $this->activeCompany->current();
        $employee = $request->attributes->get('employee_portal.employee');
        abort_unless($company instanceof Company && $employee instanceof Employee, 403);
        abort_unless($request->user() instanceof User && (int) $employee->company_id === (int) $company->getKey(), 403);

        return [$company, $employee];
    }
}
