<?php

namespace App\Actions\Employees;

use App\Data\Employees\EmployeeInvitationDeliveryResult;
use App\Mail\Employees\EmployeeInvitationMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Uri;
use Throwable;

final class InviteEmployeeToPortal
{
    public function __construct(private readonly IssueEmployeeInvitation $issueInvitation) {}

    public function execute(Company $company, Employee $employee, User $actor): EmployeeInvitationDeliveryResult
    {
        // IssueEmployeeInvitation commits its transaction before this synchronous delivery begins.
        $issued = $this->issueInvitation->execute($company, $employee, $actor);
        $timezone = $company->timezone ?: config('app.timezone');

        try {
            Mail::to($issued->invitation->email)->send(new EmployeeInvitationMail(
                employeeName: $employee->full_name,
                companyName: $company->name,
                inviterName: $actor->name ?: 'Your HR team',
                activationUrl: $this->activationUrl($issued->rawToken),
                expiresAt: $issued->invitation->expires_at->copy()->timezone($timezone)->format('j M Y, g:i A T'),
            ));

            $emailDelivered = true;
        } catch (Throwable $exception) {
            Log::warning('Employee invitation email delivery failed.', [
                'company_id' => $company->getKey(),
                'employee_id' => $employee->getKey(),
                'invitation_id' => $issued->invitation->getKey(),
                'exception_type' => $exception::class,
            ]);

            $emailDelivered = false;
        }

        return new EmployeeInvitationDeliveryResult(
            email: $issued->invitation->email,
            wasReissued: $issued->wasReissued,
            emailDelivered: $emailDelivered,
        );
    }

    private function activationUrl(string $rawToken): string
    {
        $applicationUrl = Uri::of((string) config('app.url'));
        $routePath = route('employee-invitations.show', ['token' => $rawToken], absolute: false);
        $applicationPath = trim($applicationUrl->path(), '/');
        $path = implode('/', array_filter([$applicationPath, trim($routePath, '/')], fn (string $segment): bool => $segment !== ''));

        return (string) $applicationUrl->withPath('/'.$path);
    }
}
