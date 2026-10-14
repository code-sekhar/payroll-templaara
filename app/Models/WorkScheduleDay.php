<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleDay extends Model
{
    protected $fillable = ['company_id', 'day_of_week', 'day_type', 'shift_id'];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'day_type' => WorkScheduleDayType::class,
        ];
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
