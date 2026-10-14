<?php

namespace App\Services\Attendance;

use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceCorrectionContextQuery
{
    public function __construct(private readonly WorkScheduleResolver $scheduleResolver) {}

    /** @return array{date: string, timezone: string, record: ?array{status: string, checkIn: ?string, checkOut: ?string}, punches: list<array{reference: string, label: string, type: string, time: string}>} */
    public function forDate(Employee $employee, Company $company, string $attendanceDate): array
    {
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $attendanceDate, $timezone);
        abort_unless($date !== false && $date->toDateString() === $attendanceDate, 404);

        try {
            $schedule = $this->scheduleResolver->resolve($employee, $date);
            $window = AttendancePunchWindow::forDate($date, $schedule->expectedStart, $schedule->expectedEnd);
        } catch (NoWorkScheduleAssignmentException) {
            $window = AttendancePunchWindow::forDate($date, null, null);
        }

        $record = DailyAttendanceRecord::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $attendanceDate)
            ->first(['status', 'first_check_in_at', 'last_check_out_at']);

        $punches = $this->punchesForWindow($employee, $company, $window)
            ->map(fn (AttendancePunch $punch): array => [
                'reference' => $this->reference($company, $employee, $punch),
                'label' => ucfirst(str_replace('_', ' ', $punch->punch_type->value)).' · '.$punch->punched_at->setTimezone($timezone)->format('d M Y, h:i A'),
                'type' => $punch->punch_type->value,
                'time' => $punch->punched_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
            ])->values()->all();

        return [
            'date' => $attendanceDate,
            'timezone' => $timezone,
            'record' => $record ? [
                'status' => str($record->status->value)->replace('_', ' ')->title()->toString(),
                'checkIn' => $record->first_check_in_at?->setTimezone($timezone)->format('d M Y, h:i A'),
                'checkOut' => $record->last_check_out_at?->setTimezone($timezone)->format('d M Y, h:i A'),
            ] : null,
            'punches' => $punches,
        ];
    }

    /** @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function resolveSubmittedItems(Employee $employee, Company $company, string $date, array $items): array
    {
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $workDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        abort_unless($workDate !== false && $workDate->toDateString() === $date, 422);

        try {
            $schedule = $this->scheduleResolver->resolve($employee, $workDate);
            $window = AttendancePunchWindow::forDate($workDate, $schedule->expectedStart, $schedule->expectedEnd);
        } catch (NoWorkScheduleAssignmentException) {
            $window = AttendancePunchWindow::forDate($workDate, null, null);
        }

        $validPunchIdsByReference = $this->punchesForWindow($employee, $company, $window)
            ->mapWithKeys(fn (AttendancePunch $punch): array => [$this->reference($company, $employee, $punch) => $punch->getKey()]);

        return array_map(function (array $item) use ($validPunchIdsByReference): array {
            $reference = $item['punch_reference'] ?? null;
            unset($item['punch_reference']);

            if ($reference !== null) {
                if (! $validPunchIdsByReference->has($reference)) {
                    throw ValidationException::withMessages(['items' => 'Select a punch from this attendance date.']);
                }
                $item['attendance_punch_id'] = $validPunchIdsByReference->get($reference);
            }

            return $item;
        }, $items);
    }

    private function punchesForWindow(Employee $employee, Company $company, AttendancePunchWindow $window): Collection
    {
        $punchQuery = AttendancePunch::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('punched_at', '>=', $window->start->utc());

        $window->includesEnd
            ? $punchQuery->where('punched_at', '<=', $window->end->utc())
            : $punchQuery->where('punched_at', '<', $window->end->utc());

        return $punchQuery->orderBy('punched_at')->orderBy('id')->get(['id', 'punch_type', 'punched_at', 'timezone']);
    }

    private function reference(Company $company, Employee $employee, AttendancePunch $punch): string
    {
        return hash_hmac('sha256', implode('|', [$company->getKey(), $employee->getKey(), $punch->getKey()]), (string) config('app.key'));
    }
}
