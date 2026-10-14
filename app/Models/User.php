<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Reserved team scope for roles that apply across every company. */
    public const SYSTEM_TEAM_ID = 0;

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->withPivot(['id', 'status'])
            ->withTimestamps();
    }

    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeInvitationsSent(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class, 'invited_by');
    }

    public function requestedAttendanceRegularizations(): HasMany
    {
        return $this->hasMany(AttendanceRegularizationRequest::class, 'requested_by');
    }

    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    public function isSuperAdmin(): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $teamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(self::SYSTEM_TEAM_ID);
        $this->unsetRelation('roles');

        try {
            return $this->hasRole('super_admin');
        } finally {
            $registrar->setPermissionsTeamId($teamId);
            $this->unsetRelation('roles');
        }
    }

    public function companiesWithPermission(string $permission): Collection
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $companies = new Collection;

        try {
            foreach ($this->companyUsers()->where('status', 'active')->with('company')->get() as $membership) {
                if ($membership->company?->status !== 'active') {
                    continue;
                }

                $registrar->setPermissionsTeamId($membership->company_id);
                $this->unsetRelation('roles')->unsetRelation('permissions');

                if ($this->can($permission)) {
                    $companies->push($membership->company);
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $companies;
    }

    public function hasPermissionInAnyCompany(string $permission): bool
    {
        return $this->companiesWithPermission($permission)->isNotEmpty();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
