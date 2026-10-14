<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApproved;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (getenv('EP5_NOTIFICATION_POSTGRES_TESTING') !== '1') {
        $this->markTestSkipped('Set EP5_NOTIFICATION_POSTGRES_TESTING=1 to run isolated PostgreSQL notification ownership tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'payroll_ep4_hardening_test') {
        throw new LogicException('Notification ownership tests may only use the isolated payroll_ep4_hardening_test database.');
    }

    DB::unprepared('DROP SCHEMA public CASCADE');
    DB::unprepared('CREATE SCHEMA public');
    $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
});

afterEach(function (): void {
    if (getenv('EP5_NOTIFICATION_POSTGRES_TESTING') === '1'
        && config('database.connections.pgsql.database') === 'payroll_ep4_hardening_test') {
        DB::unprepared('DROP SCHEMA public CASCADE');
        DB::unprepared('CREATE SCHEMA public');
    }
});

it('isolates one PostgreSQL User notification stream by structured Company ownership', function (): void {
    $user = User::factory()->create();
    $firstCompany = Company::factory()->create();
    $secondCompany = Company::factory()->create();

    foreach ([$firstCompany, $secondCompany] as $company) {
        CompanyUser::query()->create([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
        ]);
    }

    $user->notify(new AttendanceCorrectionApproved($firstCompany->getKey(), 1001, '2026-10-05', 'Employee One'));
    $user->notify(new AttendanceCorrectionApproved($secondCompany->getKey(), 2002, '2026-10-06', 'Employee Two'));

    $firstCompanyNotifications = $user->notifications()->where('company_id', $firstCompany->getKey())->get();
    $secondCompanyNotifications = $user->notifications()->where('company_id', $secondCompany->getKey())->get();

    expect($firstCompanyNotifications)->toHaveCount(1)
        ->and($secondCompanyNotifications)->toHaveCount(1)
        ->and((int) $firstCompanyNotifications->sole()->company_id)->toBe((int) $firstCompany->getKey())
        ->and((int) $secondCompanyNotifications->sole()->company_id)->toBe((int) $secondCompany->getKey());
});

it('enforces the Company foreign key and recipient Company index in PostgreSQL', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
    ]);

    expect(fn () => DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => AttendanceCorrectionApproved::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'company_id' => PHP_INT_MAX,
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $catalogIndex = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'notifications')
        ->where('indexname', 'notifications_company_recipient_created_index')
        ->exists();
    $companyForeignKey = DB::table('pg_constraint as constraints')
        ->join('pg_class as tables', 'tables.oid', '=', 'constraints.conrelid')
        ->where('tables.relname', 'notifications')
        ->where('constraints.contype', 'f')
        ->whereRaw("pg_get_constraintdef(constraints.oid) LIKE '%FOREIGN KEY (company_id)%'")
        ->exists();

    expect($catalogIndex)->toBeTrue()
        ->and($companyForeignKey)->toBeTrue();
});

it('keeps a synchronous PostgreSQL notification insert inside its caller transaction', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyUser::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
    ]);

    DB::beginTransaction();
    $user->notify(new AttendanceCorrectionApproved($company->getKey(), 1001, '2026-10-05', 'Employee One'));
    expect($user->notifications()->where('company_id', $company->getKey())->count())->toBe(1);
    DB::rollBack();

    expect($user->notifications()->count())->toBe(0);
});

it('rolls back and reinstalls only the notification ownership schema safely', function (): void {
    $migration = require database_path('migrations/2026_10_08_121034_add_company_ownership_to_notifications_table.php');

    $migration->down();

    expect(Schema::hasColumn('notifications', 'company_id'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('notifications', 'company_id'))->toBeTrue()
        ->and(DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'notifications')
            ->where('indexname', 'notifications_company_recipient_created_index')
            ->exists())->toBeTrue();
});
