<?php

namespace App\Notifications;

final class AttendanceCorrectionRejected extends AttendanceCorrectionNotification
{
    protected function title(): string
    {
        return 'Attendance correction rejected';
    }

    protected function message(): string
    {
        return "Your attendance correction for {$this->attendanceDate} was rejected.";
    }
}
