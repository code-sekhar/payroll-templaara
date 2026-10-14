<?php

namespace App\Mail\Employees;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EmployeeInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $companyName,
        public readonly string $inviterName,
        public readonly string $activationUrl,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Activate your employee account for {$this->companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.employees.invitation',
            with: [
                'employeeName' => $this->employeeName,
                'companyName' => $this->companyName,
                'inviterName' => $this->inviterName,
                'activationUrl' => $this->activationUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
