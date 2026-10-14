<?php

namespace App\Console\Commands;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Services\Attendance\ApprovedLeaveAttendanceSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class MaterializeApprovedLeaveAttendance extends Command
{
    protected $signature = 'attendance:materialize-leave {--request=}';

    protected $description = 'Explicitly materialize attendance for chargeable dates on an approved leave request';

    public function handle(ApprovedLeaveAttendanceSynchronizer $synchronizer): int
    {
        $requestId = filter_var($this->option('request'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $requestId) {
            $this->error('A valid --request ID is required.');

            return self::INVALID;
        }

        $request = LeaveRequest::query()->find($requestId);
        if (! $request) {
            $this->error('The leave request does not exist.');

            return self::INVALID;
        }

        if ($request->status !== LeaveRequestStatus::Approved) {
            $this->error('Only approved leave requests can be synchronized.');

            return self::INVALID;
        }

        try {
            $result = $synchronizer->synchronize($request);
        } catch (Throwable $exception) {
            $eligibleCount = $request->days()->where('requested_amount', '>', 0)->count();
            report($exception);
            $this->error(sprintf(
                'Request: LR-%s; Eligible dates: %d; Failed: 1 synchronization; no attendance changes were committed. Check application logs for details.',
                $request->getKey(),
                $eligibleCount,
            ));

            return self::FAILURE;
        }

        $employee = $request->employee;
        $processed = count($result->changedDates) + count($result->unchangedDates);
        $affectedDates = [...$result->changedDates, ...$result->unchangedDates];
        sort($affectedDates);
        $this->info(sprintf(
            'Request: LR-%s; Employee: %s; Processed: %d; Materialized: %d; Unchanged: %d; Skipped future: %d; Failed: 0.',
            $request->getKey(),
            $employee?->full_name ?? 'Unavailable employee',
            $processed,
            count($result->changedDates),
            count($result->unchangedDates),
            count($result->skippedFutureDates),
        ));
        $this->line('Affected dates: '.($affectedDates === [] ? 'none' : implode(', ', $affectedDates)).'.');
        $this->line('Skipped future dates: '.($result->skippedFutureDates === [] ? 'none' : implode(', ', $result->skippedFutureDates)).'.');

        return self::SUCCESS;
    }
}
