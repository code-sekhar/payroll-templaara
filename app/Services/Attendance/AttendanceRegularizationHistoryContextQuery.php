<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\DailyAttendanceRecordHistory;
use Carbon\CarbonImmutable;

class AttendanceRegularizationHistoryContextQuery
{
    /** @return array{captured_at: string, status: string, record_state: string, first_check_in: ?string, last_check_out: ?string, worked_minutes: int, late_minutes: int, early_leave_minutes: int, overtime_minutes: int}|null */
    public function atRequestTime(AttendanceRegularizationRequest $request, Company $company): ?array
    {
        if ((int) $request->company_id !== (int) $company->getKey()) {
            abort(404);
        }

        if ($request->daily_attendance_record_id === null) {
            return null;
        }

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $history = DailyAttendanceRecordHistory::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $request->employee_id)
            ->whereDate('attendance_date', $request->attendance_date->toDateString())
            ->where('daily_attendance_record_id', $request->daily_attendance_record_id)
            ->where('created_at', '<', $request->requested_at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first([
                'created_at', 'status', 'record_state', 'first_check_in_at', 'last_check_out_at',
                'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes',
            ]);

        if (! $history) {
            return null;
        }

        return [
            'captured_at' => CarbonImmutable::instance($history->created_at)->setTimezone($timezone)->format('d M Y, h:i A'),
            'status' => $history->status->value,
            'record_state' => $history->record_state->value,
            'first_check_in' => $history->first_check_in_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'last_check_out' => $history->last_check_out_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            'worked_minutes' => $history->worked_minutes,
            'late_minutes' => $history->late_minutes,
            'early_leave_minutes' => $history->early_leave_minutes,
            'overtime_minutes' => $history->overtime_minutes,
        ];
    }
}
