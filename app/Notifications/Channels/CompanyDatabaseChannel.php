<?php

namespace App\Notifications\Channels;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\Contracts\CompanyOwnedDatabaseNotification;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class CompanyDatabaseChannel
{
    public function send(object $notifiable, Notification $notification): DatabaseNotification
    {
        if (! $notifiable instanceof User || ! $notification instanceof CompanyOwnedDatabaseNotification) {
            throw new InvalidArgumentException('Company-owned notifications require a User and an explicit company scope.');
        }

        $companyId = $notification->companyId();
        $data = $notification->toDatabase($notifiable);

        if ($companyId < 1 || (isset($data['company_id']) && (string) $data['company_id'] !== (string) $companyId)) {
            throw new LogicException('The notification company does not match its payload.');
        }

        $data['company_id'] = $companyId;

        return $notifiable->getConnection()->transaction(function () use ($notifiable, $notification, $companyId, $data): DatabaseNotification {
            $company = Company::query()
                ->whereKey($companyId)
                ->where('status', 'active')
                ->sharedLock()
                ->first();

            if (! $company) {
                throw new LogicException('The notification company is unavailable.');
            }

            $membership = CompanyUser::query()
                ->where('company_id', $companyId)
                ->where('user_id', $notifiable->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $membership) {
                throw new LogicException('The notification recipient is not an active member of its company.');
            }

            $notificationRelation = $notifiable->notifications();

            if (! $notificationRelation instanceof MorphMany) {
                throw new LogicException('The notification recipient does not use Laravel database notifications.');
            }

            return $notificationRelation->create([
                'id' => $notification->id ?: (string) Str::uuid(),
                'type' => method_exists($notification, 'databaseType')
                    ? $notification->databaseType($notifiable)
                    : $notification::class,
                'company_id' => $companyId,
                'data' => $data,
                'read_at' => method_exists($notification, 'initialDatabaseReadAtValue')
                    ? $notification->initialDatabaseReadAtValue($notifiable)
                    : null,
            ]);
        });
    }
}
