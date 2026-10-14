<?php

namespace App\Notifications\Contracts;

interface CompanyOwnedDatabaseNotification
{
    public function companyId(): int;

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array;
}
