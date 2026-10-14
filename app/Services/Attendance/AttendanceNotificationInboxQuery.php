<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use App\Notifications\AttendanceCorrectionRejected;
use App\Notifications\AttendanceCorrectionSubmitted;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class AttendanceNotificationInboxQuery
{
    /** @return array{notifications: list<array{id: string, title: string, message: string, attendance_date: string, created_at: string, is_read: bool, open_url: string}>, unread_count: int} */
    public function forManagement(User $user, Company $company): array
    {
        $query = $user->notifications()->where('type', AttendanceCorrectionSubmitted::class);
        $this->scopeCompany($query, (int) $company->getKey());
        $notifications = $query->latest('created_at')->latest('id')->limit(50)->get();

        $requests = $this->requestsForNotifications($notifications, $company);
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $rows = [];

        foreach ($notifications as $notification) {
            $regularization = $requests[(int) ($notification->data['regularization_id'] ?? 0)] ?? null;
            if (! $regularization || ! Gate::forUser($user)->allows('view', $regularization)) {
                continue;
            }

            $rows[] = [
                'id' => (string) $notification->getKey(),
                'title' => 'Attendance correction submitted',
                'message' => (string) ($notification->data['message'] ?? 'An attendance correction was submitted.'),
                'attendance_date' => $regularization->attendance_date->toDateString(),
                'created_at' => $notification->created_at->setTimezone($timezone)->format('d M Y, h:i A'),
                'is_read' => $notification->read_at !== null,
                'open_url' => route('companies.notifications.open', [$company, $notification]),
            ];
        }

        return ['notifications' => $rows, 'unread_count' => count(array_filter($rows, fn (array $row): bool => ! $row['is_read']))];
    }

    /** @return array{notifications: list<array{id: string, title: string, message: string, attendance_date: string, created_at: string, is_read: bool, open_url: string}>, unread_count: int} */
    public function forEmployee(User $user, Company $company, Employee $employee): array
    {
        $query = $user->notifications()->whereIn('type', [AttendanceCorrectionApproved::class, AttendanceCorrectionRejected::class]);
        $this->scopeCompany($query, (int) $company->getKey());
        $notifications = $query->latest('created_at')->latest('id')->limit(50)->get();

        $requests = $this->requestsForNotifications($notifications, $company, $employee);
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $rows = [];

        foreach ($notifications as $notification) {
            $regularization = $requests[(int) ($notification->data['regularization_id'] ?? 0)] ?? null;
            if (! $regularization || ! Gate::forUser($user)->allows('view', $regularization)) {
                continue;
            }

            $approvedNotification = $notification->type === AttendanceCorrectionApproved::class;
            if (($approvedNotification && $regularization->status !== AttendanceRegularizationStatus::Approved)
                || (! $approvedNotification && $regularization->status !== AttendanceRegularizationStatus::Rejected)) {
                continue;
            }

            $rows[] = [
                'id' => (string) $notification->getKey(),
                'title' => $approvedNotification ? 'Attendance correction approved' : 'Attendance correction rejected',
                'message' => (string) ($notification->data['message'] ?? 'Your attendance correction was reviewed.'),
                'attendance_date' => $regularization->attendance_date->toDateString(),
                'created_at' => $notification->created_at->setTimezone($timezone)->format('d M Y, h:i A'),
                'is_read' => $notification->read_at !== null,
                'open_url' => route('employee.notifications.open', $notification),
            ];
        }

        return ['notifications' => $rows, 'unread_count' => count(array_filter($rows, fn (array $row): bool => ! $row['is_read']))];
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return array<int, AttendanceRegularizationRequest>
     */
    private function requestsForNotifications(iterable $notifications, Company $company, ?Employee $employee = null): array
    {
        $requestIds = collect($notifications)
            ->map(fn (DatabaseNotification $notification): int => (int) ($notification->data['regularization_id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($requestIds->isEmpty()) {
            return [];
        }

        $query = AttendanceRegularizationRequest::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $requestIds)
            ->with('employee');

        if ($employee !== null) {
            $query->where('employee_id', $employee->getKey());
        }

        return $query->get()->keyBy(fn (AttendanceRegularizationRequest $request): int => (int) $request->getKey())->all();
    }

    private function scopeCompany(MorphMany $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }
}
