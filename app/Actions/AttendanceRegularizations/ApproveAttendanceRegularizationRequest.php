<?php

namespace App\Actions\AttendanceRegularizations;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Enums\AttendanceRegularizationStatus;
use App\Enums\AttendanceRegularizationType;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Models\AttendancePunch;
use App\Models\AttendancePunchCorrection;
use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendancePunchWindow;
use App\Services\Attendance\DailyAttendanceMaterializer;
use App\Services\WorkSchedules\WorkScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ApproveAttendanceRegularizationRequest
{
    public function __construct(
        private readonly WorkScheduleResolver $workScheduleResolver,
        private readonly DailyAttendanceMaterializer $materializer,
    ) {}

    public function handle(RegularizationRequest $request, User $reviewer, ?string $reviewNote = null): RegularizationRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $reviewNote): RegularizationRequest {
            $lockedRequest = RegularizationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRequest->status !== AttendanceRegularizationStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Only pending regularization requests can be approved.']);
            }

            if ((int) $lockedRequest->requested_by === (int) $reviewer->getKey()) {
                throw ValidationException::withMessages(['request' => 'A requester cannot review their own regularization request.']);
            }

            $employee = Employee::query()
                ->where('company_id', $lockedRequest->company_id)
                ->whereKey($lockedRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();
            $company = $lockedRequest->company()->firstOrFail();
            $timezone = $company->timezone ?: config('app.timezone', 'UTC');

            try {
                $attendanceDate = CarbonImmutable::parse($lockedRequest->attendance_date->toDateString(), $timezone)->startOfDay();
            } catch (Throwable) {
                throw ValidationException::withMessages(['request' => 'The attendance date cannot be resolved in the company timezone.']);
            }

            $window = $this->windowFor($employee, $attendanceDate);
            if ($lockedRequest->daily_attendance_record_id !== null) {
                $linkedRecord = $lockedRequest->dailyAttendanceRecord()->first();
                if (! $linkedRecord
                    || (int) $linkedRecord->company_id !== (int) $lockedRequest->company_id
                    || (int) $linkedRecord->employee_id !== (int) $employee->getKey()
                    || $linkedRecord->attendance_date->toDateString() !== $attendanceDate->toDateString()) {
                    throw ValidationException::withMessages(['request' => 'The linked daily attendance record no longer matches this request.']);
                }
            }

            $items = $lockedRequest->items()->lockForUpdate()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['request' => 'A request without correction items cannot be approved.']);
            }

            $now = CarbonImmutable::now('UTC');
            $targetPunchIds = [];
            $hasCheckIn = false;
            $hasCheckOut = false;
            $hasRemoval = false;

            foreach ($items as $item) {
                if ($item->action === AttendanceRegularizationItemAction::Add) {
                    if ($item->attendance_punch_id !== null || ! $item->punch_type || ! $item->requested_punched_at || ! $item->timezone) {
                        throw ValidationException::withMessages(['request' => 'A correction item no longer has a valid ADD shape.']);
                    }
                } else {
                    if (! $item->attendance_punch_id || ($item->action !== AttendanceRegularizationItemAction::Remove && ! $item->punch_type)) {
                        throw ValidationException::withMessages(['request' => 'A correction item no longer has a valid original-punch shape.']);
                    }

                    $targetId = (int) $item->attendance_punch_id;
                    if (isset($targetPunchIds[$targetId])) {
                        throw ValidationException::withMessages(['request' => 'A raw punch cannot be targeted by multiple items in one approval.']);
                    }
                    $targetPunchIds[$targetId] = true;

                    $punch = AttendancePunch::query()
                        ->where('company_id', $lockedRequest->company_id)
                        ->where('employee_id', $employee->getKey())
                        ->whereKey($targetId)
                        ->first();

                    if (! $punch || ! $window->contains(CarbonImmutable::instance($punch->punched_at)->setTimezone($timezone))) {
                        throw ValidationException::withMessages(['request' => 'An original punch no longer belongs to this employee/date window.']);
                    }

                    $alreadyCorrected = AttendancePunchCorrection::query()
                        ->where('company_id', $lockedRequest->company_id)
                        ->where('employee_id', $employee->getKey())
                        ->where('original_attendance_punch_id', $punch->getKey())
                        ->exists();
                    if ($alreadyCorrected) {
                        throw ValidationException::withMessages(['request' => 'An original punch has already been superseded by an approved correction.']);
                    }

                    $hasRemoval = $hasRemoval || $item->action === AttendanceRegularizationItemAction::Remove;
                }

                if ($item->action === AttendanceRegularizationItemAction::Remove
                    && ($item->punch_type !== null || $item->requested_punched_at !== null || $item->timezone !== null)) {
                    throw ValidationException::withMessages(['request' => 'A REMOVE item cannot carry effective punch values.']);
                }

                if (in_array($item->action, [AttendanceRegularizationItemAction::Add, AttendanceRegularizationItemAction::Replace], true)) {
                    if (! $item->punch_type || ! $item->requested_punched_at || ! $item->timezone) {
                        throw ValidationException::withMessages(['request' => 'A correction item no longer has a valid effective punch.']);
                    }

                    $requestedAt = CarbonImmutable::instance($item->requested_punched_at)->setTimezone($timezone);
                    if (! $window->contains($requestedAt)) {
                        throw ValidationException::withMessages(['request' => 'A requested punch time is now outside the attendance calculation window.']);
                    }

                    $hasCheckIn = $hasCheckIn || $item->punch_type === AttendancePunchType::CheckIn;
                    $hasCheckOut = $hasCheckOut || $item->punch_type === AttendancePunchType::CheckOut;
                }
            }

            $this->validateRequestType($lockedRequest->request_type, $hasCheckIn, $hasCheckOut, $hasRemoval);

            foreach ($items as $item) {
                AttendancePunchCorrection::query()->create([
                    'company_id' => $lockedRequest->company_id,
                    'employee_id' => $employee->getKey(),
                    'attendance_date' => $attendanceDate->toDateString(),
                    'attendance_regularization_request_id' => $lockedRequest->getKey(),
                    'attendance_regularization_item_id' => $item->getKey(),
                    'action' => $item->action,
                    'original_attendance_punch_id' => $item->attendance_punch_id,
                    'effective_punch_type' => $item->action === AttendanceRegularizationItemAction::Remove ? null : $item->punch_type,
                    'effective_punched_at' => $item->action === AttendanceRegularizationItemAction::Remove ? null : $item->requested_punched_at,
                    'timezone' => $item->action === AttendanceRegularizationItemAction::Remove ? null : $item->timezone,
                    'approved_by' => $reviewer->getKey(),
                    'approved_at' => $now,
                    'created_at' => $now,
                ]);
            }

            $lockedRequest->forceFill([
                'status' => AttendanceRegularizationStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => $now,
                'review_note' => filled($reviewNote) ? trim($reviewNote) : null,
            ])->save();

            $this->materializer->materialize($employee, $attendanceDate, $reviewer);

            return $lockedRequest->refresh()->load(['employee', 'requestedBy', 'reviewedBy', 'items', 'corrections']);
        }, attempts: 3);
    }

    private function windowFor(Employee $employee, CarbonImmutable $date): AttendancePunchWindow
    {
        try {
            $schedule = $this->workScheduleResolver->resolve($employee, $date);

            return AttendancePunchWindow::forDate($date, $schedule->expectedStart, $schedule->expectedEnd);
        } catch (NoWorkScheduleAssignmentException) {
            return AttendancePunchWindow::forDate($date, null, null);
        }
    }

    private function validateRequestType(AttendanceRegularizationType $type, bool $hasCheckIn, bool $hasCheckOut, bool $hasRemoval): void
    {
        if (($type === AttendanceRegularizationType::MissingCheckIn && ! $hasCheckIn)
            || ($type === AttendanceRegularizationType::MissingCheckOut && ! $hasCheckOut)
            || ($type === AttendanceRegularizationType::MissingPunchPair && (! $hasCheckIn || ! $hasCheckOut))
            || ($type === AttendanceRegularizationType::DuplicatePunch && ! $hasRemoval)) {
            throw ValidationException::withMessages(['request' => 'The correction items no longer match the request type.']);
        }
    }
}
