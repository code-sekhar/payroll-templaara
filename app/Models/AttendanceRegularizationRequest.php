<?php

namespace App\Models;

use App\Enums\AttendanceRegularizationStatus;
use App\Enums\AttendanceRegularizationType;
use Database\Factories\AttendanceRegularizationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AttendanceRegularizationRequest extends Model
{
    /** @use HasFactory<AttendanceRegularizationRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'daily_attendance_record_id', 'attendance_date', 'request_type',
        'status', 'reason', 'employee_note', 'review_note', 'requested_by', 'requested_at', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'request_type' => AttendanceRegularizationType::class,
            'status' => AttendanceRegularizationStatus::class,
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function dailyAttendanceRecord(): BelongsTo
    {
        return $this->belongsTo(DailyAttendanceRecord::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AttendanceRegularizationItem::class, 'attendance_regularization_request_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendancePunchCorrection::class, 'attendance_regularization_request_id');
    }

    protected static function booted(): void
    {
        static::updating(function (AttendanceRegularizationRequest $request): void {
            $allowedReviewFields = ['status', 'review_note', 'reviewed_by', 'reviewed_at'];
            $changedFields = array_keys($request->getDirty());
            $nonReviewChanges = array_diff($changedFields, $allowedReviewFields);

            if ($nonReviewChanges !== []) {
                throw new LogicException('Submitted attendance regularization requests cannot be edited.');
            }

            $previousStatus = $request->getRawOriginal('status');
            $nextStatus = $request->status?->value;
            if ($previousStatus !== 'pending' || ! in_array($nextStatus, ['cancelled', 'approved', 'rejected'], true)) {
                throw new LogicException('Attendance regularization requests can only transition from pending to a terminal status.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Attendance regularization requests are retained as audit records.');
        });
    }
}
