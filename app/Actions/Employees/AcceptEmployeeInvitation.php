<?php

namespace App\Actions\Employees;

use App\Actions\AddCompanyMember;
use App\Data\Employees\AcceptedEmployeeInvitation;
use App\Enums\EmployeeInvitationStatus;
use App\Enums\EmploymentStatus;
use App\Exceptions\Employees\EmployeeIdentityException;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employees\EmployeeUserLinker;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AcceptEmployeeInvitation
{
    public function __construct(
        private readonly AddCompanyMember $addCompanyMember,
        private readonly EmployeeUserLinker $employeeUserLinker,
    ) {}

    public function execute(string $rawToken, ?User $authenticatedUser = null, ?string $password = null): AcceptedEmployeeInvitation
    {
        if ($rawToken === '') {
            throw new EmployeeInvitationException('INVITATION_NOT_FOUND');
        }

        $tokenHash = hash('sha256', $rawToken);
        $invitationReference = EmployeeInvitation::query()->where('token_hash', $tokenHash)->first();

        if ($invitationReference === null) {
            throw new EmployeeInvitationException('INVITATION_NOT_FOUND');
        }

        try {
            return DB::transaction(function () use ($invitationReference, $tokenHash, $authenticatedUser, $password): AcceptedEmployeeInvitation {
                $company = Company::query()->lockForUpdate()->find($invitationReference->company_id);
                $employee = Employee::withTrashed()->lockForUpdate()->find($invitationReference->employee_id);

                if ($company === null || $employee === null) {
                    throw new EmployeeInvitationException('INVITATION_NOT_FOUND');
                }

                $email = Str::lower(trim((string) $invitationReference->email));
                $matchingUsers = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit(2)
                    ->get();

                if ($matchingUsers->count() > 1) {
                    throw new EmployeeInvitationException('ACCOUNT_EMAIL_AMBIGUOUS');
                }

                $user = $matchingUsers->first();
                $membership = $user === null ? null : CompanyUser::query()
                    ->where('company_id', $company->getKey())
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();

                $invitation = EmployeeInvitation::query()
                    ->whereKey($invitationReference->getKey())
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if ($invitation === null) {
                    throw new EmployeeInvitationException('INVITATION_NOT_FOUND');
                }

                if ($invitation->status() === EmployeeInvitationStatus::Accepted) {
                    return $this->acceptedRetry($invitation, $company, $employee, $user, $membership, $authenticatedUser);
                }

                $this->assertPending($invitation);

                if ($company->status !== 'active') {
                    throw new EmployeeInvitationException('COMPANY_INACTIVE');
                }

                if ($employee->trashed() || ! in_array($employee->employment_status, [
                    EmploymentStatus::Active,
                    EmploymentStatus::Probation,
                    EmploymentStatus::NoticePeriod,
                ], true)) {
                    throw new EmployeeInvitationException('EMPLOYEE_NOT_ELIGIBLE');
                }

                if ((int) $employee->company_id !== (int) $company->getKey()
                    || (int) $invitation->company_id !== (int) $company->getKey()
                    || (int) $invitation->employee_id !== (int) $employee->getKey()) {
                    throw new EmployeeInvitationException('INVITATION_INTEGRITY_INVALID');
                }

                $currentEmployeeEmail = Str::lower(trim((string) $employee->personal_email));

                if ($currentEmployeeEmail === '' || $currentEmployeeEmail !== $email) {
                    throw new EmployeeInvitationException('INVITATION_EMAIL_CHANGED');
                }

                if ($employee->user_id !== null) {
                    throw new EmployeeInvitationException('EMPLOYEE_ALREADY_HAS_LOGIN');
                }

                $newUser = $user === null;

                if ($newUser) {
                    if ($authenticatedUser !== null) {
                        throw new EmployeeInvitationException('SIGN_OUT_REQUIRED');
                    }

                    if ($password === null || $password === '') {
                        throw new EmployeeInvitationException('PASSWORD_REQUIRED');
                    }

                    $user = User::query()->create([
                        'name' => $employee->full_name,
                        'email' => $email,
                        'password' => $password,
                    ]);
                } else {
                    if ($authenticatedUser === null) {
                        throw new EmployeeInvitationException('ACCOUNT_AUTHENTICATION_REQUIRED');
                    }

                    if ((int) $authenticatedUser->getKey() !== (int) $user->getKey()
                        || Str::lower(trim((string) $authenticatedUser->email)) !== $email) {
                        throw new EmployeeInvitationException('ACCOUNT_MISMATCH');
                    }

                    if ((int) $user->getKey() !== (int) $authenticatedUser->getKey()) {
                        throw new EmployeeInvitationException('ACCOUNT_MISMATCH');
                    }
                }

                $membership = $this->ensureMembershipAndEmployeeRole($company, $user, $membership);

                try {
                    $employee = $this->employeeUserLinker->link($company, $employee, $user);
                } catch (EmployeeIdentityException $exception) {
                    throw new EmployeeInvitationException($exception->reason);
                }

                $invitation->accepted_at = now();
                $invitation->save();

                return new AcceptedEmployeeInvitation($invitation, $user, $employee, $membership, $newUser, false);
            });
        } catch (QueryException $exception) {
            if ($this->isUserEmailUniqueViolation($exception)) {
                throw new EmployeeInvitationException('ACCOUNT_AUTHENTICATION_REQUIRED');
            }

            throw $exception;
        }
    }

    private function assertPending(EmployeeInvitation $invitation): void
    {
        match ($invitation->status()) {
            EmployeeInvitationStatus::Accepted => throw new EmployeeInvitationException('INVITATION_ALREADY_ACCEPTED'),
            EmployeeInvitationStatus::Revoked => throw new EmployeeInvitationException('INVITATION_REVOKED'),
            EmployeeInvitationStatus::Expired => throw new EmployeeInvitationException('INVITATION_EXPIRED'),
            EmployeeInvitationStatus::Pending => null,
        };
    }

    private function ensureMembershipAndEmployeeRole(Company $company, User $user, ?CompanyUser $membership): CompanyUser
    {
        if ($membership !== null && $membership->status !== 'active') {
            throw new EmployeeInvitationException('MEMBERSHIP_INACTIVE');
        }

        $role = Role::query()
            ->where('team_id', $company->getKey())
            ->where('name', 'employee')
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            throw new EmployeeInvitationException('EMPLOYEE_ROLE_UNAVAILABLE');
        }

        if ($membership === null) {
            try {
                $membership = $this->addCompanyMember->handle($company, $user, 'employee');
            } catch (ModelNotFoundException) {
                throw new EmployeeInvitationException('EMPLOYEE_ROLE_UNAVAILABLE');
            }

            return $membership->refresh();
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $membership;
    }

    private function acceptedRetry(
        EmployeeInvitation $invitation,
        Company $company,
        Employee $employee,
        ?User $matchedUser,
        ?CompanyUser $membership,
        ?User $authenticatedUser,
    ): AcceptedEmployeeInvitation {
        $linkedUser = $employee->user_id === null
            ? null
            : User::query()->find($employee->user_id);

        if ($linkedUser !== null && ($matchedUser === null || (int) $matchedUser->getKey() !== (int) $linkedUser->getKey())) {
            $membership = CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $linkedUser->getKey())
                ->first();
        }

        if ($linkedUser === null || $membership === null || $membership->status !== 'active'
            || (int) $employee->company_id !== (int) $company->getKey()
            || (int) $invitation->employee_id !== (int) $employee->getKey()
            || (int) $invitation->company_id !== (int) $company->getKey()) {
            throw new EmployeeInvitationException('ACCEPTED_INVITATION_INCONSISTENT');
        }

        if ($authenticatedUser !== null && (int) $authenticatedUser->getKey() !== (int) $linkedUser->getKey()) {
            throw new EmployeeInvitationException('ACCOUNT_MISMATCH');
        }

        return new AcceptedEmployeeInvitation($invitation, $linkedUser, $employee, $membership, false, true);
    }

    private function isUserEmailUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'users_email_unique')
            || str_contains($message, 'users.email');
    }
}
