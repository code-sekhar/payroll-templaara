<?php

namespace App\Services\Employees;

use App\Enums\EmployeeInvitationStatus;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Models\EmployeeInvitation;

class EmployeeInvitationResolver
{
    public function resolve(string $rawToken): EmployeeInvitation
    {
        if ($rawToken === '') {
            throw new EmployeeInvitationException('INVITATION_NOT_FOUND');
        }

        $invitation = EmployeeInvitation::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($invitation === null) {
            throw new EmployeeInvitationException('INVITATION_NOT_FOUND');
        }

        match ($invitation->status()) {
            EmployeeInvitationStatus::Accepted => throw new EmployeeInvitationException('INVITATION_ALREADY_ACCEPTED'),
            EmployeeInvitationStatus::Revoked => throw new EmployeeInvitationException('INVITATION_REVOKED'),
            EmployeeInvitationStatus::Expired => throw new EmployeeInvitationException('INVITATION_EXPIRED'),
            EmployeeInvitationStatus::Pending => null,
        };

        return $invitation;
    }
}
