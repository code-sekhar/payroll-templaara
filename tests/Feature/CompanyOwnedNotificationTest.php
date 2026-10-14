<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use App\Notifications\Channels\CompanyDatabaseChannel;
use App\Notifications\Contracts\CompanyOwnedDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

final class QueuedCompanyOwnedTestNotification extends Notification implements CompanyOwnedDatabaseNotification, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $ownerCompanyId) {}

    public function via(object $notifiable): array
    {
        return [CompanyDatabaseChannel::class];
    }

    public function companyId(): int
    {
        return $this->ownerCompanyId;
    }

    public function toDatabase(object $notifiable): array
    {
        return ['message' => 'Company-scoped test notification.'];
    }
}

final class MismatchedCompanyTestNotification extends Notification implements CompanyOwnedDatabaseNotification
{
    public function __construct(private readonly int $ownerCompanyId, private readonly int $payloadCompanyId) {}

    public function via(object $notifiable): array
    {
        return [CompanyDatabaseChannel::class];
    }

    public function companyId(): int
    {
        return $this->ownerCompanyId;
    }

    public function toDatabase(object $notifiable): array
    {
        return ['company_id' => $this->payloadCompanyId, 'message' => 'Invalid scope.'];
    }
}

final class LegacyDatabaseTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['message' => 'Legacy global notification.'];
    }
}

function companyNotificationMembership(Company $company, User $user): void
{
    CompanyUser::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
    ]);
}

test('company-owned notification writes structured ownership and isolates a multi-company recipient', function () {
    $firstCompany = Company::factory()->create();
    $secondCompany = Company::factory()->create();
    $user = User::factory()->create();
    companyNotificationMembership($firstCompany, $user);
    companyNotificationMembership($secondCompany, $user);

    $user->notify(new AttendanceCorrectionApproved($firstCompany->getKey(), 101, '2026-10-05', 'Employee One'));
    $user->notify(new AttendanceCorrectionApproved($secondCompany->getKey(), 202, '2026-10-06', 'Employee Two'));

    expect($user->notifications()->where('company_id', $firstCompany->getKey())->count())->toBe(1)
        ->and($user->notifications()->where('company_id', $secondCompany->getKey())->count())->toBe(1)
        ->and($user->notifications()->where('company_id', $firstCompany->getKey())->first()->data['company_id'])->toBe($firstCompany->getKey())
        ->and(Schema::hasColumn('notifications', 'company_id'))->toBeTrue();
});

test('standard Laravel database notifications remain compatible and company-neutral', function () {
    $user = User::factory()->create();

    $user->notify(new LegacyDatabaseTestNotification);

    expect($user->notifications()->sole()->company_id)->toBeNull()
        ->and($user->notifications()->sole()->data)->toBe(['message' => 'Legacy global notification.']);
});

test('company-owned notification rejects a recipient without active company membership', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();

    expect(fn () => $user->notify(new AttendanceCorrectionApproved($company->getKey(), 101, '2026-10-05', 'Employee One')))
        ->toThrow(LogicException::class, 'The notification recipient is not an active member of its company.');

    expect($user->notifications()->count())->toBe(0);
});

test('company-owned notification rejects mismatched structured and payload ownership', function () {
    $firstCompany = Company::factory()->create();
    $secondCompany = Company::factory()->create();
    $user = User::factory()->create();
    companyNotificationMembership($firstCompany, $user);

    expect(fn () => $user->notify(new MismatchedCompanyTestNotification($firstCompany->getKey(), $secondCompany->getKey())))
        ->toThrow(LogicException::class, 'The notification company does not match its payload.');

    expect($user->notifications()->count())->toBe(0);
});

test('synchronous company notification insert rolls back with the surrounding transaction', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    companyNotificationMembership($company, $user);

    DB::beginTransaction();
    $user->notify(new AttendanceCorrectionApproved($company->getKey(), 101, '2026-10-05', 'Employee One'));

    expect($user->notifications()->where('company_id', $company->getKey())->count())->toBe(1);

    DB::rollBack();

    expect($user->notifications()->count())->toBe(0);
});

test('queued company notification carries after-commit semantics and writes scope without request context', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    companyNotificationMembership($company, $user);
    Queue::fake();

    DB::transaction(function () use ($company, $user): void {
        $user->notify((new QueuedCompanyOwnedTestNotification($company->getKey()))->afterCommit());
    });

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->afterCommit === true);
    $job = unserialize(serialize(Queue::pushed(SendQueuedNotifications::class)->first()));
    $job->handle(app(ChannelManager::class));

    expect($user->notifications()->where('company_id', $company->getKey())->count())->toBe(1)
        ->and($user->notifications()->first()->data['company_id'])->toBe($company->getKey());
});
