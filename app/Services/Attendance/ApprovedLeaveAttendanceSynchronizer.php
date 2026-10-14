<?php

namespace App\Services\Attendance;

use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ApprovedLeaveAttendanceSynchronizer
{
    public function __construct(private readonly DailyAttendanceMaterializer $materializer) {}

    public function synchronize(LeaveRequest $leaveRequest, ?User $actor = null): ApprovedLeaveAttendanceSynchronizationResult
    {
        $attempts = DB::transactionLevel() === 0 ? 3 : 1;

        return DB::transaction(function () use ($leaveRequest, $actor): ApprovedLeaveAttendanceSynchronizationResult {
            $request = LeaveRequest::query()
                ->where('company_id', $leaveRequest->company_id)
                ->whereKey($leaveRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== LeaveRequestStatus::Approved) {
                throw ValidationException::withMessages(['request' => 'Only approved leave requests can be synchronized.']);
            }

            $employee = Employee::withTrashed()
                ->where('company_id', $request->company_id)
                ->whereKey($request->employee_id)
                ->lockForUpdate()
                ->first();
            $company = Company::query()->whereKey($request->company_id)->first();

            if (! $employee || ! $company || (int) $employee->company_id !== (int) $request->company_id) {
                throw ValidationException::withMessages(['request' => 'The approved leave employee or company reference is unavailable.']);
            }

            $days = $request->days()->orderBy('leave_date')->orderBy('id')->lockForUpdate()->get();
            $chargeableDays = $this->validateSnapshots($request, $days);
            $timezone = $company->timezone ?: config('app.timezone', 'UTC');
            $today = CarbonImmutable::now($timezone)->toDateString();
            $changedDates = [];
            $unchangedDates = [];
            $skippedFutureDates = [];

            foreach ($chargeableDays as $day) {
                $date = $day->leave_date->toDateString();

                if ($date > $today) {
                    $skippedFutureDates[] = $date;

                    continue;
                }

                $record = DailyAttendanceRecord::query()
                    ->where('company_id', $request->company_id)
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('attendance_date', $date)
                    ->first();
                $historyCount = $record?->histories()->count() ?? 0;

                $this->materializer->materialize(
                    $employee,
                    CarbonImmutable::parse($date, $timezone),
                    $actor,
                );

                $updatedRecord = DailyAttendanceRecord::query()
                    ->where('company_id', $request->company_id)
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('attendance_date', $date)
                    ->firstOrFail();

                if ($updatedRecord->histories()->count() > $historyCount) {
                    $changedDates[] = $date;
                } else {
                    $unchangedDates[] = $date;
                }
            }

            return new ApprovedLeaveAttendanceSynchronizationResult(
                $changedDates,
                $unchangedDates,
                $skippedFutureDates,
            );
        }, attempts: $attempts);
    }

    /**
     * @param  Collection<int, LeaveRequestDay>  $days
     * @return list<LeaveRequestDay>
     */
    private function validateSnapshots(LeaveRequest $request, Collection $days): array
    {
        $expectedDateCount = (int) $request->start_date->diffInDays($request->end_date) + 1;
        if ($days->count() !== $expectedDateCount) {
            throw ValidationException::withMessages(['request' => 'The approved leave day snapshots are incomplete.']);
        }

        $sumCents = 0;
        $seenDates = [];
        $chargeableDays = [];

        foreach ($days as $day) {
            try {
                $amountCents = LeaveAmount::toCents((string) $day->requested_amount);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['request' => 'An approved leave day amount is invalid.']);
            }

            $date = $day->leave_date->toDateString();
            if ((int) $day->company_id !== (int) $request->company_id
                || (int) $day->employee_id !== (int) $request->employee_id
                || (int) $day->leave_request_id !== (int) $request->getKey()
                || $date < $request->start_date->toDateString()
                || $date > $request->end_date->toDateString()
                || isset($seenDates[$date])
                || $amountCents < 0
                || ($day->day_type === LeaveRequestDayType::FullDay && $amountCents !== 100)
                || ($day->day_type === LeaveRequestDayType::HalfDay && $amountCents !== 50)
                || (in_array($day->day_type, [LeaveRequestDayType::OffDay, LeaveRequestDayType::Holiday], true) && $amountCents !== 0)) {
                throw ValidationException::withMessages(['request' => 'An approved leave day snapshot is inconsistent.']);
            }

            $seenDates[$date] = true;
            $sumCents += $amountCents;

            if ($amountCents > 0) {
                $chargeableDays[] = $day;
            }
        }

        if ($sumCents !== LeaveAmount::toCents((string) $request->requested_days) || $sumCents <= 0) {
            throw ValidationException::withMessages(['request' => 'The approved leave amount does not match its saved day snapshots.']);
        }

        return $chargeableDays;
    }
}
