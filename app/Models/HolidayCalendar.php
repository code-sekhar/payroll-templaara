<?php

namespace App\Models;

use App\Enums\HolidayCalendarStatus;
use Database\Factories\HolidayCalendarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HolidayCalendar extends Model
{
    /** @use HasFactory<HolidayCalendarFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'year', 'description', 'status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'status' => HolidayCalendarStatus::class];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeHolidayCalendarAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
