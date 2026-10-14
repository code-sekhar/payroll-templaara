<?php

namespace App\Models;

use Database\Factories\AttendanceWorksiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceWorksite extends Model
{
    /** @use HasFactory<AttendanceWorksiteFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'latitude', 'longitude', 'radius_meters', 'is_active'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'radius_meters' => 'integer', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function attendancePolicies(): BelongsToMany
    {
        return $this->belongsToMany(AttendancePolicy::class, 'attendance_policy_worksites')->withPivot('company_id');
    }
}
