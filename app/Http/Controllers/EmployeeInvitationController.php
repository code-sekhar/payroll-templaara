<?php

namespace App\Http\Controllers;

use App\Actions\Employees\AcceptEmployeeInvitation;
use App\Exceptions\Employees\EmployeeInvitationException;
use App\Http\Requests\AcceptEmployeeInvitationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeInvitationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class EmployeeInvitationController extends Controller
{
    public function show(Request $request, string $token, EmployeeInvitationResolver $resolver): Response
    {
        try {
            $invitation = $resolver->resolve($token);
        } catch (EmployeeInvitationException $exception) {
            return Inertia::render('employee-invitations/show', [
                'state' => $this->publicState($exception->reason),
                'invitation' => null,
                'accountState' => 'none',
                'acceptUrl' => null,
                'loginUrl' => route('login'),
                'logoutUrl' => route('logout'),
            ]);
        }

        $company = Company::query()->find($invitation->company_id);
        $employee = Employee::withTrashed()->find($invitation->employee_id);

        if ($company === null || $employee === null) {
            return Inertia::render('employee-invitations/show', [
                'state' => 'unavailable',
                'invitation' => null,
                'accountState' => 'none',
                'acceptUrl' => null,
                'loginUrl' => route('login'),
                'logoutUrl' => route('logout'),
            ]);
        }

        $email = Str::lower(trim((string) $invitation->email));
        $matchingUsers = User::query()->whereRaw('LOWER(email) = ?', [$email])->limit(2)->get(['id']);
        $accountExists = $matchingUsers->isNotEmpty();
        $authenticatedUser = $request->user();
        $authenticatedAccountMatches = $authenticatedUser instanceof User
            && $accountExists
            && (int) $matchingUsers->first()->getKey() === (int) $authenticatedUser->getKey();

        if ($accountExists && $authenticatedUser === null) {
            $request->session()->put('url.intended', route('employee-invitations.show', ['token' => $token]));
        }

        $accountState = match (true) {
            ! $accountExists && $authenticatedUser === null => 'new_account',
            ! $accountExists && $authenticatedUser instanceof User => 'sign_out_required',
            $authenticatedAccountMatches => 'existing_account_authenticated',
            $accountExists && $authenticatedUser === null => 'existing_account_sign_in',
            default => 'wrong_account',
        };

        return Inertia::render('employee-invitations/show', [
            'state' => 'pending',
            'invitation' => [
                'company' => $company->name,
                'employee' => $employee->full_name,
                'email' => $invitation->email,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            'accountState' => $accountState,
            'acceptUrl' => route('employee-invitations.accept', ['token' => $token]),
            'loginUrl' => route('login'),
            'logoutUrl' => route('logout'),
        ]);
    }

    public function accept(AcceptEmployeeInvitationRequest $request, string $token, AcceptEmployeeInvitation $accept): RedirectResponse
    {
        try {
            $result = $accept->execute($token, $request->user() instanceof User ? $request->user() : null, $request->validated('password'));
        } catch (EmployeeInvitationException $exception) {
            if ($exception->reason === 'ACCOUNT_AUTHENTICATION_REQUIRED') {
                $request->session()->put('url.intended', route('employee-invitations.show', ['token' => $token]));

                return to_route('login')->with('status', 'Sign in with the invited email address to continue.');
            }

            if ($exception->reason === 'PASSWORD_REQUIRED') {
                return back()->withErrors(['password' => 'Choose a password to activate your account.']);
            }

            if ($exception->reason === 'INVITATION_ALREADY_ACCEPTED') {
                return to_route('employee-invitations.show', ['token' => $token]);
            }

            if (in_array($exception->reason, ['INVITATION_NOT_FOUND', 'INVITATION_EXPIRED', 'INVITATION_REVOKED'], true)) {
                return to_route('employee-invitations.show', ['token' => $token]);
            }

            return back()->withErrors(['invitation' => $this->friendlyMessage($exception->reason)]);
        }

        if ($result->alreadyAccepted && ! $request->user()) {
            return to_route('login')->with('status', 'This invitation has already been used. Sign in to continue.');
        }

        if ($result->newUser) {
            return to_route('login')->with('status', 'Your account is ready. You can now sign in.');
        }

        return to_route('dashboard')->with('success', 'Employee access was activated successfully.');
    }

    private function publicState(string $reason): string
    {
        return match ($reason) {
            'INVITATION_EXPIRED' => 'expired',
            'INVITATION_REVOKED' => 'revoked',
            'INVITATION_ALREADY_ACCEPTED' => 'accepted',
            default => 'invalid',
        };
    }

    private function friendlyMessage(string $reason): string
    {
        return match ($reason) {
            'INVITATION_EXPIRED' => 'This invitation has expired. Please ask your HR team to send a new one.',
            'INVITATION_REVOKED' => 'This invitation is no longer active. Please ask your HR team for a new invitation.',
            'INVITATION_EMAIL_CHANGED' => 'The invited email has changed. Please ask your HR team to send a new invitation.',
            'ACCOUNT_MISMATCH', 'SIGN_OUT_REQUIRED' => 'Sign in with the email address shown in this invitation to continue.',
            'MEMBERSHIP_INACTIVE' => 'This account cannot currently join the company. Please contact your HR team.',
            'COMPANY_INACTIVE', 'EMPLOYEE_NOT_ELIGIBLE', 'EMPLOYEE_ALREADY_HAS_LOGIN', 'USER_ALREADY_LINKED_IN_COMPANY', 'EMPLOYEE_ALREADY_LINKED' => 'This invitation can no longer be used. Please contact your HR team.',
            default => 'We could not activate this invitation. Please contact your HR team for help.',
        };
    }
}
