<?php

namespace App\Models;

use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['company_id', 'date', 'name', 'description', 'type', 'status', 'is_optional', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => HolidayType::class,
            'status' => HolidayStatus::class,
            'is_optional' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function holidayCalendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class)->withTrashed();
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
