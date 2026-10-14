<?php

namespace App\Models;

use App\Enums\EmployeeInvitationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeInvitation extends Model
{
    public const EXPIRY_HOURS = 48;

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function status(): EmployeeInvitationStatus
    {
        if ($this->accepted_at !== null) {
            return EmployeeInvitationStatus::Accepted;
        }

        if ($this->revoked_at !== null) {
            return EmployeeInvitationStatus::Revoked;
        }

        if ($this->expires_at === null || $this->expires_at->lessThanOrEqualTo(now())) {
            return EmployeeInvitationStatus::Expired;
        }

        return EmployeeInvitationStatus::Pending;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
