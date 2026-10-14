<?php

namespace App\Actions\Employees;

use App\Data\Employees\IssuedEmployeeInvitation;
use App\Enums\EmploymentStatus;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class IssueEmployeeInvitation
{
    public function execute(Company $company, Employee $employee, User $actor): IssuedEmployeeInvitation
    {
        return DB::transaction(function () use ($company, $employee, $actor): IssuedEmployeeInvitation {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->getKey());
            $lockedEmployee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());

            if ($lockedCompany->status !== 'active') {
                throw new EmployeeInvitationException('COMPANY_INACTIVE');
            }

            if ((int) $lockedEmployee->company_id !== (int) $lockedCompany->getKey()) {
                throw new EmployeeInvitationException('EMPLOYEE_COMPANY_MISMATCH');
            }

            if ($lockedEmployee->trashed()) {
                throw new EmployeeInvitationException('EMPLOYEE_ARCHIVED');
            }

            if (! in_array($lockedEmployee->employment_status, [
                EmploymentStatus::Active,
                EmploymentStatus::Probation,
                EmploymentStatus::NoticePeriod,
            ], true)) {
                throw new EmployeeInvitationException('EMPLOYEE_NOT_ELIGIBLE');
            }

            if ($lockedEmployee->user_id !== null) {
                throw new EmployeeInvitationException('EMPLOYEE_ALREADY_HAS_LOGIN');
            }

            $email = trim((string) $lockedEmployee->personal_email);

            if ($email === '') {
                throw new EmployeeInvitationException('EMPLOYEE_EMAIL_REQUIRED');
            }

            if (Validator::make(['email' => $email], [
                'email' => ['required', 'string', 'email', 'max:255'],
            ])->fails()) {
                throw new EmployeeInvitationException('EMPLOYEE_EMAIL_INVALID');
            }

            $email = Str::lower($email);

            $membership = CompanyUser::query()
                ->where('company_id', $lockedCompany->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $actor->isSuperAdmin() && $membership === null) {
                throw new EmployeeInvitationException('ACTOR_NOT_AUTHORIZED');
            }

            $this->authorizeActor($actor, $lockedCompany, $membership !== null);

            $issuedAt = now();
            $wasReissued = EmployeeInvitation::query()
                ->where('company_id', $lockedCompany->getKey())
                ->where('employee_id', $lockedEmployee->getKey())
                ->exists();
            $pendingInvitations = EmployeeInvitation::query()
                ->pending()
                ->where('company_id', $lockedCompany->getKey())
                ->where('employee_id', $lockedEmployee->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pendingInvitations as $pendingInvitation) {
                $pendingInvitation->revoked_at = $issuedAt;
                $pendingInvitation->save();
            }

            $rawToken = Str::random(64);
            $invitation = new EmployeeInvitation;
            $invitation->company_id = $lockedCompany->getKey();
            $invitation->employee_id = $lockedEmployee->getKey();
            $invitation->email = $email;
            $invitation->token_hash = hash('sha256', $rawToken);
            $invitation->expires_at = $issuedAt->copy()->addHours(EmployeeInvitation::EXPIRY_HOURS);
            $invitation->invited_by = $actor->getKey();
            $invitation->save();

            return new IssuedEmployeeInvitation($invitation, $rawToken, $wasReissued);
        });
    }

    private function authorizeActor(User $actor, Company $company, bool $hasActiveMembership): void
    {
        $isSuperAdmin = $actor->isSuperAdmin();

        if (! $isSuperAdmin && ! $hasActiveMembership) {
            throw new EmployeeInvitationException('ACTOR_NOT_AUTHORIZED');
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        try {
            if (! $isSuperAdmin && ! $actor->can('employee.login.invite')) {
                throw new EmployeeInvitationException('ACTOR_NOT_AUTHORIZED');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
