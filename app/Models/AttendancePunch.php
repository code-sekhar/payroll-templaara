<?php

namespace App\Models;

use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AttendancePunch extends Model
{
    /** @use HasFactory<AttendancePunchFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'punch_type', 'punched_at', 'timezone', 'source',
        'device_identifier', 'external_reference', 'latitude', 'longitude',
        'location_accuracy_meters', 'ip_address', 'user_agent', 'notes', 'created_by',
        'location_mode', 'location_result', 'worksite_id', 'worksite_name_snapshot',
        'location_distance_meters', 'worksite_radius_meters_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'punch_type' => AttendancePunchType::class,
            'punched_at' => 'immutable_datetime',
            'source' => AttendancePunchSource::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'location_accuracy_meters' => 'decimal:2',
            'location_distance_meters' => 'decimal:2',
            'worksite_radius_meters_snapshot' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Raw attendance punches cannot be edited.');
        });

        static::deleting(function (): void {
            throw new LogicException('Raw attendance punches cannot be deleted.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorksite::class)->withTrashed();
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendancePunchCorrection::class, 'original_attendance_punch_id');
    }
}
