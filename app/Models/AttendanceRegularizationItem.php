<?php

namespace App\Models;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use Database\Factories\AttendanceRegularizationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AttendanceRegularizationItem extends Model
{
    /** @use HasFactory<AttendanceRegularizationItemFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'attendance_regularization_request_id', 'action', 'attendance_punch_id',
        'punch_type', 'requested_punched_at', 'timezone', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'action' => AttendanceRegularizationItemAction::class,
            'punch_type' => AttendancePunchType::class,
            'requested_punched_at' => 'immutable_datetime',
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegularizationRequest::class, 'attendance_regularization_request_id');
    }

    public function attendancePunch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class);
    }

    public function correction(): HasOne
    {
        return $this->hasOne(AttendancePunchCorrection::class, 'attendance_regularization_item_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Attendance regularization items are immutable after submission.');
        });

        static::deleting(function (): never {
            throw new LogicException('Attendance regularization items are retained as audit records.');
        });
    }
}
