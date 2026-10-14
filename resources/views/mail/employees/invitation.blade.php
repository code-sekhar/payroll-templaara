<x-mail::message>
# You've been invited to join {{ $companyName }}

Hello {{ $employeeName }},

{{ $inviterName }} has invited you to activate your employee account for {{ $companyName }}.

Use the button below to create or connect your account.

<x-mail::button :url="$activationUrl">
Activate Employee Account
</x-mail::button>

This invitation expires on {{ $expiresAt }}.

If you were not expecting this invitation, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
