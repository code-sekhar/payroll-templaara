<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceRegularizationType;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

class EmployeeAttendanceCorrectionHistoryQuery
{
    /** @return list<array<string, mixed>> */
    public function forMonth(Employee $employee, Company $company, string $month, User $user): array
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone)->startOfMonth();
        $end = $start->addMonth();

        return AttendanceRegularizationRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('attendance_date', '>=', $start->toDateString())
            ->where('attendance_date', '<', $end->toDateString())
            ->with(['items.attendancePunch'])
            ->orderByDesc('requested_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn (AttendanceRegularizationRequest $request): array => [
                'date' => $request->attendance_date->toDateString(),
                'dateLabel' => $request->attendance_date->format('j M Y'),
                'type' => $request->request_type->value,
                'typeLabel' => $this->typeLabel($request->request_type),
                'status' => $request->status->value,
                'statusLabel' => str($request->status->value)->replace('_', ' ')->title()->toString(),
                'reason' => $request->reason,
                'requestedAt' => $request->requested_at->setTimezone($timezone)->format('d M Y, h:i A'),
                'canCancel' => $user->can('cancel', $request),
                'cancelReference' => $user->can('cancel', $request)
                    ? hash_hmac('sha256', implode('|', [$company->getKey(), $employee->getKey(), $request->getKey()]), (string) config('app.key'))
                    : null,
                'items' => $request->items->map(fn ($item): array => [
                    'action' => str($item->action->value)->title()->toString(),
                    'punchType' => $item->punch_type?->value ? str($item->punch_type->value)->replace('_', ' ')->title()->toString() : null,
                    'originalTime' => $item->attendancePunch?->punched_at->setTimezone($item->attendancePunch->timezone)->format('d M Y, h:i A'),
                    'requestedTime' => $item->requested_punched_at?->setTimezone($item->timezone)->format('d M Y, h:i A'),
                ])->values()->all(),
            ])->values()->all();
    }

    private function typeLabel(AttendanceRegularizationType $type): string
    {
        return match ($type) {
            AttendanceRegularizationType::MissingCheckIn => 'Missing check-in',
            AttendanceRegularizationType::MissingCheckOut => 'Missing check-out',
            AttendanceRegularizationType::MissingPunchPair => 'Missing check-in and check-out',
            AttendanceRegularizationType::IncorrectPunch => 'Incorrect punch time',
            AttendanceRegularizationType::DuplicatePunch => 'Duplicate punch',
            AttendanceRegularizationType::Other => 'Other attendance correction',
        };
    }
}
