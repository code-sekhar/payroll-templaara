<?php

namespace App\Models;

use App\Enums\WorkScheduleStatus;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'description', 'status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['status' => WorkScheduleStatus::class];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class)->orderBy('day_of_week');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkScheduleAssignment::class);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $builder): Builder => $builder
            ->where('name', 'like', '%'.$search.'%')
            ->orWhere('code', 'like', '%'.$search.'%'));
    }

    protected static function booted(): void
    {
        static::saving(function (WorkSchedule $schedule): void {
            $schedule->code = Str::upper(trim($schedule->code));
        });
    }
}
