<?php

namespace App\Models;

use App\Enums\AttendancePolicyStatus;
use App\Enums\PunchLocationMode;
use Database\Factories\AttendancePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendancePolicy extends Model
{
    /** @use HasFactory<AttendancePolicyFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'description', 'status', 'grace_in_minutes', 'grace_out_minutes',
        'late_after_minutes', 'early_leave_after_minutes', 'half_day_before_minutes',
        'absent_before_minutes', 'overtime_enabled', 'overtime_after_minutes', 'location_mode', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'status' => 'active',
        'grace_in_minutes' => 0,
        'grace_out_minutes' => 0,
        'late_after_minutes' => 0,
        'early_leave_after_minutes' => 0,
        'half_day_before_minutes' => 0,
        'absent_before_minutes' => 0,
        'overtime_enabled' => false,
        'overtime_after_minutes' => 0,
        'location_mode' => 'disabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendancePolicyStatus::class,
            'grace_in_minutes' => 'integer',
            'grace_out_minutes' => 'integer',
            'late_after_minutes' => 'integer',
            'early_leave_after_minutes' => 'integer',
            'half_day_before_minutes' => 'integer',
            'absent_before_minutes' => 'integer',
            'overtime_enabled' => 'boolean',
            'overtime_after_minutes' => 'integer',
            'location_mode' => PunchLocationMode::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function worksites(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceWorksite::class, 'attendance_policy_worksites')
            ->withPivot('company_id');
    }
}
