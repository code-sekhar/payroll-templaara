<?php

namespace App\Models;

use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceRecordState;
use Database\Factories\DailyAttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyAttendanceRecord extends Model
{
    /** @use HasFactory<DailyAttendanceRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'attendance_date', 'status', 'record_state',
        'expected_start_at', 'expected_end_at', 'expected_minutes', 'first_check_in_at', 'last_check_out_at',
        'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'is_late', 'is_early_leave',
        'work_schedule_id', 'work_schedule_assignment_id', 'shift_id', 'holiday_calendar_id', 'holiday_id',
        'attendance_policy_id', 'attendance_policy_assignment_id', 'calculated_at', 'finalized_at',
        'leave_request_id', 'leave_request_day_id', 'leave_type_id', 'leave_amount', 'leave_is_paid',
        'calculation_version', 'created_by', 'updated_by',
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
            'finalized_at' => 'immutable_datetime',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class)->withTrashed();
    }

    public function workScheduleAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkScheduleAssignment::class, 'work_schedule_assignment_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function holidayCalendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class)->withTrashed();
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class)->withTrashed();
    }

    public function attendancePolicy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class)->withTrashed();
    }

    public function attendancePolicyAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendancePolicyAssignment::class, 'attendance_policy_assignment_id')->withTrashed();
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DailyAttendanceRecordHistory::class);
    }
}
