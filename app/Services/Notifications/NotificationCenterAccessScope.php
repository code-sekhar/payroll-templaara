<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationCenterAccessScope
{
    public function __construct(
        private readonly AttendanceNotificationAccessScope $attendanceScope,
        private readonly LeaveNotificationAccessScope $leaveScope,
    ) {}

    /** @return MorphMany<DatabaseNotification, User> */
    public function forRecipient(User $user, Company $company): MorphMany
    {
        $attendanceIds = $this->attendanceScope->forRecipient($user, $company)->select('notifications.id');
        $leaveIds = $this->leaveScope->forRecipient($user, $company)->select('notifications.id');

        return $user->notifications()
            ->where('notifications.company_id', $company->getKey())
            ->where(function (Builder $notifications) use ($attendanceIds, $leaveIds): void {
                $notifications->whereIn('notifications.id', $attendanceIds)
                    ->orWhereIn('notifications.id', $leaveIds);
            });
    }
}
