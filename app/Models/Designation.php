<?php

namespace App\Models;

use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'code', 'description', 'level', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
