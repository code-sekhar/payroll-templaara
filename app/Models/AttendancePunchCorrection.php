<?php

namespace App\Models;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use Database\Factories\AttendancePunchCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AttendancePunchCorrection extends Model
{
    /** @use HasFactory<AttendancePunchCorrectionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'employee_id', 'attendance_date', 'attendance_regularization_request_id',
        'attendance_regularization_item_id', 'action', 'original_attendance_punch_id',
        'effective_punch_type', 'effective_punched_at', 'timezone', 'approved_by', 'approved_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'action' => AttendanceRegularizationItemAction::class,
            'effective_punch_type' => AttendancePunchType::class,
            'effective_punched_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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

    public function regularizationRequest(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegularizationRequest::class, 'attendance_regularization_request_id');
    }

    public function regularizationItem(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegularizationItem::class, 'attendance_regularization_item_id');
    }

    public function originalAttendancePunch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class, 'original_attendance_punch_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Approved attendance punch corrections are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Approved attendance punch corrections are retained as audit records.');
        });
    }
}
