<?php

namespace App\Services\Employees;

use App\Enums\EmployeeInvitationStatus;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class EmployeeLoginAccessPresenter
{
    /** @return array{status: string, email: ?string, invitationEmail: ?string, sentAt: ?string, expiresAt: ?string, emailChanged: bool, canInvite: bool, unavailableReason: ?string} */
    public function present(Employee $employee, bool $canManageLoginAccess): array
    {
        $user = $employee->user_id === null ? null : ($employee->relationLoaded('user') ? $employee->user : $employee->user()->first());

        if ($employee->user_id !== null && $user !== null) {
            return [
                'status' => 'active',
                'email' => $user->email,
                'invitationEmail' => null,
                'sentAt' => null,
                'expiresAt' => null,
                'emailChanged' => false,
                'canInvite' => false,
                'unavailableReason' => null,
            ];
        }

        $currentEmail = trim((string) $employee->personal_email);
        $emailIsValid = $currentEmail !== '' && ! Validator::make(['email' => $currentEmail], [
            'email' => ['required', 'email', 'max:255'],
        ])->fails();
        $isEligible = ! $employee->trashed() && in_array($employee->employment_status, [
            EmploymentStatus::Active,
            EmploymentStatus::Probation,
            EmploymentStatus::NoticePeriod,
        ], true);

        if (! $isEligible || ! $emailIsValid) {
            return [
                'status' => 'unavailable',
                'email' => $currentEmail !== '' ? $currentEmail : null,
                'invitationEmail' => null,
                'sentAt' => null,
                'expiresAt' => null,
                'emailChanged' => false,
                'canInvite' => false,
                'unavailableReason' => ! $isEligible ? 'employee_inactive' : ($currentEmail === '' ? 'email_missing' : 'email_invalid'),
            ];
        }

        $invitation = EmployeeInvitation::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('id')
            ->first();

        if ($invitation?->status() === EmployeeInvitationStatus::Pending) {
            return [
                'status' => 'pending',
                'email' => $currentEmail,
                'invitationEmail' => $invitation->email,
                'sentAt' => $invitation->created_at?->toIso8601String(),
                'expiresAt' => $invitation->expires_at?->toIso8601String(),
                'emailChanged' => Str::lower(trim($invitation->email)) !== Str::lower($currentEmail),
                'canInvite' => $canManageLoginAccess,
                'unavailableReason' => null,
            ];
        }

        $status = $invitation === null ? 'not_invited' : 'expired';

        return [
            'status' => $status,
            'email' => $currentEmail,
            'invitationEmail' => null,
            'sentAt' => $invitation?->created_at?->toIso8601String(),
            'expiresAt' => $invitation?->expires_at?->toIso8601String(),
            'emailChanged' => false,
            'canInvite' => $canManageLoginAccess,
            'unavailableReason' => null,
        ];
    }
}
