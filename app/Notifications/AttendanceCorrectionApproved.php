<?php

namespace App\Notifications;

final class AttendanceCorrectionApproved extends AttendanceCorrectionNotification
{
    protected function title(): string
    {
        return 'Attendance correction approved';
    }

    protected function message(): string
    {
        return "Your attendance correction for {$this->attendanceDate} was approved. Attendance has been recalculated.";
    }
}
