<?php

namespace App\Actions\AttendanceRegularizations;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Enums\AttendanceRegularizationType;
use App\Enums\CalculatedAttendanceStatus;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendancePunchWindow;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateAttendanceRegularizationRequest
{
    public function __construct(
        private readonly WorkScheduleResolver $workScheduleResolver,
    ) {}

    /** @param array{attendance_date: string, request_type: string, reason: string, employee_note?: ?string, items: list<array<string, mixed>>} $data */
    public function handle(Company $company, Employee $employee, User $actor, array $data, bool $selfRequest = false): AttendanceRegularizationRequest
    {
        return DB::transaction(function () use ($company, $employee, $actor, $data, $selfRequest): AttendanceRegularizationRequest {
            $lockedEmployee = Employee::query()
                ->where('company_id', $company->id)
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $timezone = $company->timezone ?: config('app.timezone', 'UTC');
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['attendance_date'], $timezone);
                if (! $date || $date->toDateString() !== $data['attendance_date']) {
                    throw new \InvalidArgumentException('Invalid date format.');
                }
            } catch (Throwable $exception) {
                throw ValidationException::withMessages(['attendance_date' => 'Choose a valid attendance date.']);
            }

            if ($date->toDateString() > CarbonImmutable::now($timezone)->toDateString()) {
                throw ValidationException::withMessages(['attendance_date' => 'Regularization cannot be requested for a future attendance date.']);
            }

            if ($selfRequest && $lockedEmployee->joining_date && $date->toDateString() < $lockedEmployee->joining_date->toDateString()) {
                throw ValidationException::withMessages(['attendance_date' => 'Regularization cannot be requested before the employment start date.']);
            }

            $existingPending = AttendanceRegularizationRequest::query()
                ->where('company_id', $company->id)
                ->where('employee_id', $lockedEmployee->id)
                ->whereDate('attendance_date', $date->toDateString())
                ->where('status', 'pending')
                ->exists();

            if ($existingPending) {
                throw ValidationException::withMessages(['attendance_date' => 'A pending regularization request already exists for this employee and date.']);
            }

            $dailyRecord = DailyAttendanceRecord::query()
                ->where('company_id', $company->id)
                ->where('employee_id', $lockedEmployee->id)
                ->whereDate('attendance_date', $date->toDateString())
                ->first();

            if ($selfRequest && $date->toDateString() === CarbonImmutable::now($timezone)->toDateString()
                && $data['request_type'] === AttendanceRegularizationType::MissingCheckOut->value
                && $dailyRecord?->status === CalculatedAttendanceStatus::InProgress) {
                throw ValidationException::withMessages(['attendance_date' => 'Wait until today’s attendance is complete before requesting a missing check-out.']);
            }

            $window = $this->punchWindow($lockedEmployee, $date);
            $items = $data['items'];
            $this->validateItems($company, $lockedEmployee, $window, $timezone, $items);
            $this->validateTypeConsistency(AttendanceRegularizationType::from($data['request_type']), $items);

            $request = AttendanceRegularizationRequest::query()->create([
                'company_id' => $company->id,
                'employee_id' => $lockedEmployee->id,
                'daily_attendance_record_id' => $dailyRecord?->id,
                'attendance_date' => $date->toDateString(),
                'request_type' => AttendanceRegularizationType::from($data['request_type']),
                'status' => 'pending',
                'reason' => trim($data['reason']),
                'employee_note' => filled($data['employee_note'] ?? null) ? trim((string) $data['employee_note']) : null,
                'requested_by' => $actor->id,
                'requested_at' => CarbonImmutable::now('UTC'),
            ]);

            foreach ($items as $item) {
                $action = AttendanceRegularizationItemAction::from($item['action']);
                $requestedAt = isset($item['requested_punched_at'])
                    ? CarbonImmutable::parse($item['requested_punched_at'], $timezone)->utc()
                    : null;

                $request->items()->create([
                    'company_id' => $company->id,
                    'employee_id' => $lockedEmployee->id,
                    'action' => $action,
                    'attendance_punch_id' => $item['attendance_punch_id'] ?? null,
                    'punch_type' => isset($item['punch_type']) ? AttendancePunchType::from($item['punch_type']) : null,
                    'requested_punched_at' => $requestedAt,
                    'timezone' => $requestedAt ? $timezone : null,
                    'notes' => filled($item['notes'] ?? null) ? trim((string) $item['notes']) : null,
                ]);
            }

            return $request->load(['employee', 'dailyAttendanceRecord', 'requestedBy', 'items.attendancePunch']);
        });
    }

    /** @param list<array<string, mixed>> $items */
    private function validateItems(Company $company, Employee $employee, AttendancePunchWindow $window, string $timezone, array $items): void
    {
        foreach ($items as $index => $item) {
            $action = AttendanceRegularizationItemAction::from($item['action']);
            $punchId = $item['attendance_punch_id'] ?? null;

            if ($action === AttendanceRegularizationItemAction::Add
                && ($punchId !== null || blank($item['punch_type'] ?? null) || blank($item['requested_punched_at'] ?? null))) {
                throw ValidationException::withMessages(["items.{$index}" => 'An added punch needs a type and requested time, and cannot reference an existing punch.']);
            }

            if ($action === AttendanceRegularizationItemAction::Replace
                && (blank($punchId) || blank($item['punch_type'] ?? null) || blank($item['requested_punched_at'] ?? null))) {
                throw ValidationException::withMessages(["items.{$index}" => 'A replacement needs an original punch, type, and requested time.']);
            }

            if ($action === AttendanceRegularizationItemAction::Remove
                && (filled($item['punch_type'] ?? null) || filled($item['requested_punched_at'] ?? null))) {
                throw ValidationException::withMessages(["items.{$index}" => 'A removed punch cannot include replacement values.']);
            }

            if ($action !== AttendanceRegularizationItemAction::Add) {
                $punch = AttendancePunch::query()
                    ->where('company_id', $company->id)
                    ->where('employee_id', $employee->id)
                    ->whereKey($punchId)
                    ->first();

                if (! $punch) {
                    throw ValidationException::withMessages(["items.{$index}.attendance_punch_id" => 'Choose a punch belonging to this employee and company.']);
                }

                if (! $window->contains(CarbonImmutable::instance($punch->punched_at)->setTimezone($timezone))) {
                    throw ValidationException::withMessages(["items.{$index}.attendance_punch_id" => 'The selected punch is outside the attendance calculation window for this date.']);
                }
            }

            if (in_array($action, [AttendanceRegularizationItemAction::Add, AttendanceRegularizationItemAction::Replace], true)) {
                try {
                    $requestedAt = CarbonImmutable::parse($item['requested_punched_at'], $timezone)->setTimezone($timezone);
                } catch (Throwable $exception) {
                    throw ValidationException::withMessages(["items.{$index}.requested_punched_at" => 'Enter a valid requested punch time.']);
                }

                if (! $window->contains($requestedAt)) {
                    throw ValidationException::withMessages(["items.{$index}.requested_punched_at" => 'The requested punch time is outside the attendance calculation window for this date.']);
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function validateTypeConsistency(AttendanceRegularizationType $type, array $items): void
    {
        $hasCheckInCorrection = collect($items)->contains(fn (array $item): bool => in_array($item['action'], ['add', 'replace'], true) && ($item['punch_type'] ?? null) === 'check_in');
        $hasCheckOutCorrection = collect($items)->contains(fn (array $item): bool => in_array($item['action'], ['add', 'replace'], true) && ($item['punch_type'] ?? null) === 'check_out');
        $hasRemoval = collect($items)->contains(fn (array $item): bool => $item['action'] === 'remove');

        if (($type === AttendanceRegularizationType::MissingCheckIn && ! $hasCheckInCorrection)
            || ($type === AttendanceRegularizationType::MissingCheckOut && ! $hasCheckOutCorrection)
            || ($type === AttendanceRegularizationType::MissingPunchPair && (! $hasCheckInCorrection || ! $hasCheckOutCorrection))
            || ($type === AttendanceRegularizationType::DuplicatePunch && ! $hasRemoval)) {
            throw ValidationException::withMessages(['items' => 'The correction items do not match the selected request type.']);
        }
    }

    private function punchWindow(Employee $employee, CarbonImmutable $date): AttendancePunchWindow
    {
        try {
            $schedule = $this->workScheduleResolver->resolve($employee, $date);

            return AttendancePunchWindow::forDate($date, $schedule?->expectedStart, $schedule?->expectedEnd);
        } catch (NoWorkScheduleAssignmentException) {
            return AttendancePunchWindow::forDate($date, null, null);
        }
    }
}
