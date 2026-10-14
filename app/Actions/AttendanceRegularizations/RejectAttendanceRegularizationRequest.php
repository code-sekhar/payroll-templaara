<?php

namespace App\Actions\AttendanceRegularizations;

use App\Enums\AttendanceRegularizationStatus;
use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RejectAttendanceRegularizationRequest
{
    public function handle(RegularizationRequest $request, User $reviewer, string $reviewNote): RegularizationRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $reviewNote): RegularizationRequest {
            $lockedRequest = RegularizationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRequest->status !== AttendanceRegularizationStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Only pending regularization requests can be rejected.']);
            }

            if ((int) $lockedRequest->requested_by === (int) $reviewer->getKey()) {
                throw ValidationException::withMessages(['request' => 'A requester cannot review their own regularization request.']);
            }

            $lockedRequest->forceFill([
                'status' => AttendanceRegularizationStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now('UTC'),
                'review_note' => trim($reviewNote),
            ])->save();

            return $lockedRequest->refresh();
        }, attempts: 3);
    }
}
