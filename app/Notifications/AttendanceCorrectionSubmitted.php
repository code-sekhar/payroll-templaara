<?php

namespace App\Notifications;

final class AttendanceCorrectionSubmitted extends AttendanceCorrectionNotification
{
    protected function title(): string
    {
        return 'Attendance correction submitted';
    }

    protected function message(): string
    {
        return "{$this->employeeName} submitted an attendance correction for {$this->attendanceDate}.";
    }
}
