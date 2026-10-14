<?php

namespace App\Notifications;

use App\Notifications\Channels\CompanyDatabaseChannel;
use App\Notifications\Contracts\CompanyOwnedDatabaseNotification;
use Illuminate\Notifications\Notification;

abstract class LeaveRequestNotification extends Notification implements CompanyOwnedDatabaseNotification
{
    public function __construct(
        private readonly int $companyId,
        private readonly int $leaveRequestId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [CompanyDatabaseChannel::class];
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    /** @return array{company_id: int, leave_request_id: int} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'company_id' => $this->companyId,
            'leave_request_id' => $this->leaveRequestId,
        ];
    }
}
