<?php

namespace App\Actions\AttendanceRegularizations;

use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendanceRegularizationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelAttendanceRegularizationRequest
{
    public function handle(AttendanceRegularizationRequest $request): AttendanceRegularizationRequest
    {
        return DB::transaction(function () use ($request): AttendanceRegularizationRequest {
            $lockedRequest = AttendanceRegularizationRequest::query()
                ->where('company_id', $request->company_id)
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== AttendanceRegularizationStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Only pending regularization requests can be cancelled.']);
            }

            $lockedRequest->status = AttendanceRegularizationStatus::Cancelled;
            $lockedRequest->save();

            return $lockedRequest->load(['employee', 'requestedBy', 'items.attendancePunch']);
        });
    }
}
