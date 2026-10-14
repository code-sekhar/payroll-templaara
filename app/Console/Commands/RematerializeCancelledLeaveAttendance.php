<?php

namespace App\Console\Commands;

use App\Actions\LeaveRequests\RematerializeCancelledLeaveAttendance as RematerializeAction;
use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Throwable;

final class RematerializeCancelledLeaveAttendance extends Command
{
    protected $signature = 'attendance:rematerialize-cancelled-leave {--request=}';

    protected $description = 'Recalculate attendance dates affected by a cancelled approved leave request';

    public function handle(RematerializeAction $action): int
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

        try {
            $result = $action->execute($request);
        } catch (ApprovedLeaveCancellationException $exception) {
            $this->error("Request LR-{$requestId} cannot be synchronized: {$exception->reason}.");

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->error("Request LR-{$requestId} synchronization could not start: ".class_basename($exception).'.');

            return self::INVALID;
        }

        foreach ($result->failedDates as $failure) {
            $this->warn("FAILED date={$failure['date']} reason={$failure['reason']}");
        }

        $this->info(sprintf(
            'Request: LR-%s; Affected: %d; Processed: %d; Changed: %d; Unchanged: %d; Failed: %d.',
            $request->getKey(),
            count($result->affectedDates),
            count($result->processedDates),
            count($result->changedDates),
            count($result->unchangedDates),
            count($result->failedDates),
        ));

        return $result->failedDates === [] ? self::SUCCESS : self::FAILURE;
    }
}
