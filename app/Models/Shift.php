<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'start_time', 'end_time', 'break_duration_minutes', 'grace_period_minutes',
        'is_overnight', 'status', 'description',
    ];

    protected function casts(): array
    {
        return [
            'break_duration_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'is_overnight' => 'boolean',
            'status' => ShiftStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employeeShiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function workScheduleDays(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class);
    }

    public static function durationMinutesFor(string $startTime, string $endTime): int
    {
        $startMinutes = self::minutesSinceMidnight($startTime);
        $endMinutes = self::minutesSinceMidnight($endTime);
        $duration = $endMinutes - $startMinutes;

        return $duration < 0 ? $duration + 1440 : $duration;
    }

    public static function isOvernightTime(string $startTime, string $endTime): bool
    {
        return self::minutesSinceMidnight($endTime) < self::minutesSinceMidnight($startTime);
    }

    public function getDurationMinutesAttribute(): int
    {
        return self::durationMinutesFor($this->start_time, $this->end_time);
    }

    public function getNetDurationMinutesAttribute(): int
    {
        return $this->duration_minutes - $this->break_duration_minutes;
    }

    private static function minutesSinceMidnight(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }
}
