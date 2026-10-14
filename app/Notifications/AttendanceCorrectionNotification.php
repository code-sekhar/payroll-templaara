<?php

namespace App\Notifications;

use App\Notifications\Channels\CompanyDatabaseChannel;
use App\Notifications\Contracts\CompanyOwnedDatabaseNotification;
use Illuminate\Notifications\Notification;

abstract class AttendanceCorrectionNotification extends Notification implements CompanyOwnedDatabaseNotification
{
    public function __construct(
        protected readonly int $companyId,
        protected readonly int $regularizationId,
        protected readonly string $attendanceDate,
        protected readonly string $employeeName,
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

    /** @return array{company_id: int, regularization_id: int, attendance_date: string, employee_name: string, title: string, message: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'company_id' => $this->companyId,
            'regularization_id' => $this->regularizationId,
            'attendance_date' => $this->attendanceDate,
            'employee_name' => $this->employeeName,
            'title' => $this->title(),
            'message' => $this->message(),
        ];
    }

    abstract protected function title(): string;

    abstract protected function message(): string;
}
