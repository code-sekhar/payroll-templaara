<?php

namespace App\Models;

use App\Enums\LeaveRequestDayType;
use Database\Factories\LeaveRequestDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeaveRequestDay extends Model
{
    /** @use HasFactory<LeaveRequestDayFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'leave_request_id', 'employee_id', 'leave_date', 'day_type', 'requested_amount',
        'work_schedule_id', 'work_schedule_assignment_id', 'holiday_calendar_id', 'holiday_id',
    ];

    protected function casts(): array
    {
        return ['leave_date' => 'date', 'day_type' => LeaveRequestDayType::class, 'requested_amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Leave request day snapshots are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Leave request day snapshots are immutable.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
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

    public function holidayCalendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class)->withTrashed();
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class)->withTrashed();
    }
}
