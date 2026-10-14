<?php

namespace App\Models;

use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use Database\Factories\EmployeeWorkScheduleAssignmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeWorkScheduleAssignment extends Model
{
    /** @use HasFactory<EmployeeWorkScheduleAssignmentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['employee_id', 'work_schedule_id', 'effective_from', 'effective_to', 'status', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => EmployeeWorkScheduleAssignmentStatus::class,
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeEffectiveOn(Builder $query, DateTimeInterface|string $date): Builder
    {
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $query->where('status', EmployeeWorkScheduleAssignmentStatus::Active->value)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $builder): Builder => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function scopeOverlappingPeriod(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string|null $to): Builder
    {
        $from = $from instanceof DateTimeInterface ? $from->format('Y-m-d') : $from;
        $to = $to instanceof DateTimeInterface ? $to->format('Y-m-d') : $to;

        return $query->where(fn (Builder $builder): Builder => $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($to !== null, fn (Builder $builder): Builder => $builder->whereDate('effective_from', '<=', $to));
    }
}
