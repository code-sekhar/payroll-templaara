<?php

namespace App\Models;

use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceHistoryReason;
use App\Enums\DailyAttendanceRecordState;
use Database\Factories\DailyAttendanceRecordHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DailyAttendanceRecordHistory extends Model
{
    /** @use HasFactory<DailyAttendanceRecordHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'daily_attendance_record_id', 'employee_id', 'attendance_date', 'status', 'record_state',
        'expected_start_at', 'expected_end_at', 'expected_minutes', 'first_check_in_at', 'last_check_out_at',
        'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'is_late', 'is_early_leave',
        'leave_request_id', 'leave_request_day_id', 'leave_type_id', 'leave_amount', 'leave_is_paid',
        'calculation_version', 'calculated_at', 'change_reason', 'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'status' => CalculatedAttendanceStatus::class,
            'record_state' => DailyAttendanceRecordState::class,
            'expected_start_at' => 'immutable_datetime',
            'expected_end_at' => 'immutable_datetime',
            'first_check_in_at' => 'immutable_datetime',
            'last_check_out_at' => 'immutable_datetime',
            'calculated_at' => 'immutable_datetime',
            'change_reason' => DailyAttendanceHistoryReason::class,
            'expected_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'is_late' => 'boolean',
            'is_early_leave' => 'boolean',
            'leave_amount' => 'decimal:2',
            'leave_is_paid' => 'boolean',
            'calculation_version' => 'integer',
        ];
    }

    public function dailyAttendanceRecord(): BelongsTo
    {
        return $this->belongsTo(DailyAttendanceRecord::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function leaveRequestDay(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestDay::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Daily attendance record history is append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Daily attendance record history is append-only.');
        });
    }
}
