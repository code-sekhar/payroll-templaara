<?php

namespace App\Services\Notifications;

use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use App\Notifications\AttendanceCorrectionRejected;
use App\Notifications\AttendanceCorrectionSubmitted;
use App\Notifications\LeaveRequestApproved;
use App\Notifications\LeaveRequestRejected;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\LeaveRequestWithdrawn;
use App\Services\Employees\EmployeePortalAccess;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Tenancy\ActiveCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NotificationCenterQuery
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly NotificationCenterAccessScope $accessScope,
        private readonly EmployeePortalAccess $employeePortalAccess,
        private readonly LeaveRequestReviewerScope $leaveReviewerScope,
    ) {}

    public function page(User $user, Company $company, string $filter = 'all', bool $employeePortal = false): NotificationCenterData
    {
        $this->assertActiveMembership($user, $company);
        $query = $this->filtered($this->accessScope->forRecipient($user, $company), $filter);
        /** @var LengthAwarePaginator<int, DatabaseNotification> $paginator */
        $paginator = $query
            ->orderByDesc('notifications.created_at')
            ->orderByDesc('notifications.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $notifications = $paginator->getCollection();
        $rows = $this->safeRows($user, $company, $notifications, $employeePortal);
        $unreadCount = $this->accessScope->forRecipient($user, $company)->whereNull('notifications.read_at')->count();

        return new NotificationCenterData(
            $rows,
            $unreadCount,
            $filter,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'links' => $paginator->linkCollection()->map(fn (array $link): array => [
                    'url' => $link['url'],
                    'label' => strip_tags((string) $link['label']),
                    'active' => (bool) $link['active'],
                ])->values()->all(),
            ],
        );
    }

    public function markAsRead(User $user, Company $company, string $notificationId): string
    {
        $this->assertActiveMembership($user, $company);
        $notification = $this->accessScope->forRecipient($user, $company)
            ->whereKey($notificationId)
            ->firstOrFail();

        $rows = $this->safeRows($user, $company, new EloquentCollection([$notification]));
        if ($rows === []) {
            throw new NotFoundHttpException;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $rows[0]['destination_url'];
    }

    public function markAllAsRead(User $user, Company $company): int
    {
        $this->assertActiveMembership($user, $company);
        $marked = 0;
        $query = $this->accessScope->forRecipient($user, $company)
            ->whereNull('notifications.read_at')
            ->orderBy('notifications.id');

        $query->chunkById(100, function (EloquentCollection $notifications) use ($user, $company, &$marked): void {
            $authorizedIds = collect($this->safeRows($user, $company, $notifications))
                ->pluck('id')
                ->all();

            foreach ($notifications as $notification) {
                if (in_array((string) $notification->getKey(), $authorizedIds, true) && $notification->read_at === null) {
                    $notification->markAsRead();
                    $marked++;
                }
            }
        }, 'notifications.id', 'id');

        return $marked;
    }

    private function assertActiveMembership(User $user, Company $company): void
    {
        if (app(ActiveCompany::class)->current()?->is($company) !== true) {
            throw new AuthorizationException;
        }

        $hasActiveMembership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->exists();

        if (! $hasActiveMembership) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  MorphMany<DatabaseNotification, User>  $query
     * @return MorphMany<DatabaseNotification, User>
     */
    private function filtered(MorphMany $query, string $filter): MorphMany
    {
        if ($filter === 'unread') {
            $query->whereNull('notifications.read_at');
        }

        return $query;
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array{id: string, title: string, summary: string, category: string, created_at: string, created_at_iso: string, is_read: bool, destination_url: string, destination_label: string, read_url: string, icon: string}>
     */
    private function safeRows(User $user, Company $company, iterable $notifications, bool $employeePortal = false): array
    {
        $notifications = collect($notifications)->values();
        if ($notifications->isEmpty()) {
            return [];
        }

        $requestIds = $notifications
            ->map(fn (DatabaseNotification $notification): ?int => $this->requestId($notification))
            ->filter()
            ->unique()
            ->values();
        $requests = $requestIds->isEmpty()
            ? new EloquentCollection
            : AttendanceRegularizationRequest::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $requestIds)
                ->with('employee')
                ->get()
                ->keyBy(fn (AttendanceRegularizationRequest $request): int => (int) $request->getKey());
        $employee = $this->employeePortalAccess->employeeFor($user, $company);
        $leaveRequestIds = $notifications
            ->filter(fn (DatabaseNotification $notification): bool => $this->isLeaveNotification($notification))
            ->map(fn (DatabaseNotification $notification): ?int => $this->leaveRequestId($notification))
            ->filter()
            ->unique()
            ->values();
        $leaveRequests = new EloquentCollection;
        if ($leaveRequestIds->isNotEmpty()) {
            if ($user->can('leave_request.view')) {
                $reviewerRequests = $this->leaveReviewerScope
                    ->apply(LeaveRequest::query()->whereKey($leaveRequestIds->all()), $user, $company)
                    ->get()
                    ->keyBy(fn (LeaveRequest $request): int => (int) $request->getKey());
                foreach ($reviewerRequests as $requestId => $reviewerRequest) {
                    $leaveRequests->put((int) $requestId, $reviewerRequest);
                }
            }

            if ($employee instanceof Employee && $user->can('leave_request.self_view')) {
                $employeeRequests = LeaveRequest::query()
                    ->where('company_id', $company->getKey())
                    ->where('employee_id', $employee->getKey())
                    ->whereKey($leaveRequestIds->all())
                    ->get()
                    ->keyBy(fn (LeaveRequest $request): int => (int) $request->getKey());
                foreach ($employeeRequests as $requestId => $employeeRequest) {
                    $leaveRequests->put((int) $requestId, $employeeRequest);
                }
            }
        }
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $rows = [];

        foreach ($notifications as $notification) {
            if ($this->isLeaveNotification($notification)) {
                $leaveRequest = $leaveRequests->get($this->leaveRequestId($notification) ?? 0);
                if (! $leaveRequest instanceof LeaveRequest) {
                    continue;
                }

                $destination = $this->leaveDestination($notification, $leaveRequest, $employee, $user);
                if ($destination === null) {
                    continue;
                }

                $createdAt = $notification->created_at->setTimezone($timezone);
                $rows[] = [
                    'id' => (string) $notification->getKey(),
                    'title' => $destination['title'],
                    'summary' => $destination['summary'],
                    'category' => 'leave',
                    'created_at' => $createdAt->format('d M Y, h:i A'),
                    'created_at_iso' => $createdAt->toIso8601String(),
                    'is_read' => $notification->read_at !== null,
                    'destination_url' => $destination['url'],
                    'destination_label' => $destination['label'],
                    'read_url' => route($employeePortal ? 'employee.notifications.read' : 'notifications.read', $notification->getKey()),
                    'icon' => 'calendar-days',
                ];

                continue;
            }

            $request = $requests->get($this->requestId($notification) ?? 0);
            if (! $request instanceof AttendanceRegularizationRequest) {
                continue;
            }

            $destination = $this->destination($notification, $request, $employee, $company);
            if ($destination === null) {
                continue;
            }

            $createdAt = $notification->created_at->setTimezone($timezone);
            $rows[] = [
                'id' => (string) $notification->getKey(),
                'title' => $destination['title'],
                'summary' => $destination['summary'],
                'category' => 'attendance',
                'created_at' => $createdAt->format('d M Y, h:i A'),
                'created_at_iso' => $createdAt->toIso8601String(),
                'is_read' => $notification->read_at !== null,
                'destination_url' => $destination['url'],
                'destination_label' => $destination['label'],
                'read_url' => route($employeePortal ? 'employee.notifications.read' : 'notifications.read', $notification->getKey()),
                'icon' => 'calendar-check',
            ];
        }

        return $rows;
    }

    private function isLeaveNotification(DatabaseNotification $notification): bool
    {
        return in_array($notification->type, [
            LeaveRequestSubmitted::class,
            LeaveRequestApproved::class,
            LeaveRequestRejected::class,
            LeaveRequestWithdrawn::class,
        ], true);
    }

    private function leaveRequestId(DatabaseNotification $notification): ?int
    {
        $value = $notification->data['leave_request_id'] ?? null;
        if (! is_int($value) && (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1)) {
            return null;
        }

        $requestId = (int) $value;

        return $requestId > 0 ? $requestId : null;
    }

    /** @return array{title: string, summary: string, url: string, label: string}|null */
    private function leaveDestination(DatabaseNotification $notification, LeaveRequest $request, ?Employee $employee, User $user): ?array
    {
        if ((int) $request->company_id !== (int) $notification->company_id) {
            return null;
        }

        if (in_array($notification->type, [LeaveRequestSubmitted::class, LeaveRequestWithdrawn::class], true)) {
            if (! $user->can('leave_request.view')) {
                return null;
            }

            return [
                'title' => $notification->type === LeaveRequestSubmitted::class ? 'Leave request submitted' : 'Leave request withdrawn',
                'summary' => $notification->type === LeaveRequestSubmitted::class
                    ? 'A leave request is awaiting your review.'
                    : 'A pending leave request was withdrawn.',
                'url' => route('leave.approvals.show', $request->getKey()),
                'label' => 'View leave request',
            ];
        }

        if ($employee instanceof Employee && $user->can('leave_request.self_view')
            && (int) $request->employee_id === (int) $employee->getKey()
            && in_array($notification->type, [LeaveRequestApproved::class, LeaveRequestRejected::class], true)) {
            return [
                'title' => $notification->type === LeaveRequestApproved::class ? 'Leave request approved' : 'Leave request rejected',
                'summary' => $notification->type === LeaveRequestApproved::class
                    ? 'Your leave request has been approved.'
                    : 'Your leave request has been rejected.',
                'url' => route('employee.leave.requests.show', $request->getKey()),
                'label' => 'View your request',
            ];
        }

        return null;
    }

    /** @return array{title: string, summary: string, url: string, label: string}|null */
    private function destination(DatabaseNotification $notification, AttendanceRegularizationRequest $request, ?Employee $employee, Company $company): ?array
    {
        if ($notification->type === AttendanceCorrectionSubmitted::class) {
            return [
                'title' => 'Attendance correction submitted',
                'summary' => 'An attendance correction request is ready for review.',
                'url' => route('companies.attendance-regularizations.show', [$company, $request]),
                'label' => 'View attendance request',
            ];
        }

        if ($employee instanceof Employee
            && (int) $request->employee_id === (int) $employee->getKey()
            && $notification->type === AttendanceCorrectionApproved::class
            && $request->status === AttendanceRegularizationStatus::Approved) {
            return [
                'title' => 'Attendance correction approved',
                'summary' => 'Your attendance correction was approved.',
                'url' => route('employee.attendance.index', ['month' => $request->attendance_date->format('Y-m')]),
                'label' => 'View attendance',
            ];
        }

        if ($employee instanceof Employee
            && (int) $request->employee_id === (int) $employee->getKey()
            && $notification->type === AttendanceCorrectionRejected::class
            && $request->status === AttendanceRegularizationStatus::Rejected) {
            return [
                'title' => 'Attendance correction rejected',
                'summary' => 'Your attendance correction was rejected.',
                'url' => route('employee.attendance.index', ['month' => $request->attendance_date->format('Y-m')]),
                'label' => 'View attendance',
            ];
        }

        return null;
    }

    private function requestId(DatabaseNotification $notification): ?int
    {
        $value = $notification->data['regularization_id'] ?? null;
        if (! is_int($value) && (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1)) {
            return null;
        }

        $requestId = (int) $value;

        return $requestId > 0 ? $requestId : null;
    }
}
