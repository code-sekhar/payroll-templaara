<?php

use App\Actions\WorkSchedules\CreateWorkSchedule;
use App\Actions\WorkSchedules\UpdateWorkSchedule;
use App\Enums\WorkScheduleDayType;
use App\Models\Company;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') !== '1') {
        $this->markTestSkipped('Set EP4_POSTGRES_TESTING=1 to run destructive PostgreSQL tests against the isolated EP4 test database.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'payroll_ep4_hardening_test') {
        throw new LogicException('PostgreSQL Work Schedule tests may only use the payroll_ep4_hardening_test database.');
    }

    DB::unprepared('DROP SCHEMA public CASCADE');
    DB::unprepared('CREATE SCHEMA public');
    $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
});

afterEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') === '1'
        && config('database.connections.pgsql.database') === 'payroll_ep4_hardening_test') {
        DB::unprepared('DROP SCHEMA public CASCADE');
        DB::unprepared('CREATE SCHEMA public');
    }
});

it('creates a valid schedule through the canonical action and deferred transaction', function (): void {
    [$company, $actor, $shift] = postgresWorkScheduleFixture();

    $schedule = app(CreateWorkSchedule::class)->handle($company, $actor, postgresWorkSchedulePayload($shift));

    expect($schedule->days)->toHaveCount(7)
        ->and($schedule->days()->count())->toBe(7);
});

it('rejects an incomplete schedule when the deferred transaction commits', function (): void {
    [$company, , $shift] = postgresWorkScheduleFixture();
    $payload = postgresWorkSchedulePayload($shift);

    expect(fn () => DB::transaction(function () use ($company, $payload): void {
        $scheduleId = DB::table('work_schedules')->insertGetId([
            'company_id' => $company->getKey(),
            'name' => $payload['name'],
            'code' => $payload['code'],
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_slice($payload['days'], 0, 6) as $day) {
            DB::table('work_schedule_days')->insert([
                'company_id' => $company->getKey(),
                'work_schedule_id' => $scheduleId,
                'day_of_week' => $day['day_of_week'],
                'day_type' => $day['day_type'],
                'shift_id' => $day['shift_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }))->toThrow(PDOException::class);

    expect(DB::table('work_schedules')->count())->toBe(0)
        ->and(DB::table('work_schedule_days')->count())->toBe(0);
});

it('allows canonical schedule updates while keeping seven valid day records', function (): void {
    [$company, $actor, $shift] = postgresWorkScheduleFixture();
    $schedule = app(CreateWorkSchedule::class)->handle($company, $actor, postgresWorkSchedulePayload($shift));

    $updated = app(UpdateWorkSchedule::class)->handle(
        $company,
        $schedule,
        $actor,
        postgresWorkSchedulePayload($shift, ['name' => 'Updated schedule', 'code' => 'UPDATED']),
    );

    expect($updated->name)->toBe('Updated schedule')
        ->and($updated->days)->toHaveCount(7)
        ->and(DB::table('work_schedule_days')->where('work_schedule_id', $schedule->getKey())->count())->toBe(7);
});

it('checks both parent schedules when a day is moved within a deferred transaction', function (): void {
    [$company, $actor, $shift] = postgresWorkScheduleFixture();
    $first = app(CreateWorkSchedule::class)->handle($company, $actor, postgresWorkSchedulePayload($shift));
    $second = app(CreateWorkSchedule::class)->handle($company, $actor, postgresWorkSchedulePayload($shift, [
        'name' => 'Second schedule',
        'code' => 'SECOND',
    ]));

    DB::transaction(function () use ($first, $second, $company): void {
        $firstMonday = DB::table('work_schedule_days')
            ->where('work_schedule_id', $first->getKey())->where('day_of_week', 1)->first();
        $secondMonday = DB::table('work_schedule_days')
            ->where('work_schedule_id', $second->getKey())->where('day_of_week', 1)->first();

        DB::table('work_schedule_days')->where('id', $secondMonday->id)->delete();
        DB::table('work_schedule_days')->where('id', $firstMonday->id)
            ->update(['work_schedule_id' => $second->getKey(), 'updated_at' => now()]);
        DB::table('work_schedule_days')->insert([
            'company_id' => $company->getKey(),
            'work_schedule_id' => $first->getKey(),
            'day_of_week' => 1,
            'day_type' => $secondMonday->day_type,
            'shift_id' => $secondMonday->shift_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(DB::table('work_schedule_days')->where('work_schedule_id', $first->getKey())->count())->toBe(7)
        ->and(DB::table('work_schedule_days')->where('work_schedule_id', $second->getKey())->count())->toBe(7);
});

it('rejects deleting a required day from an existing schedule', function (): void {
    [$company, $actor, $shift] = postgresWorkScheduleFixture();
    $schedule = app(CreateWorkSchedule::class)->handle($company, $actor, postgresWorkSchedulePayload($shift));

    expect(fn () => DB::transaction(fn () => DB::table('work_schedule_days')
        ->where('work_schedule_id', $schedule->getKey())->where('day_of_week', 1)->delete()))
        ->toThrow(PDOException::class);

    expect(DB::table('work_schedule_days')->where('work_schedule_id', $schedule->getKey())->count())->toBe(7);
});

it('refuses to repair invalid historical schedules without rewriting their rows', function (): void {
    [$company] = postgresWorkScheduleFixture();
    DB::statement('ALTER TABLE work_schedules DISABLE TRIGGER work_schedules_require_seven_days');
    $scheduleId = DB::table('work_schedules')->insertGetId([
        'company_id' => $company->getKey(),
        'name' => 'Invalid historical schedule',
        'code' => 'INVALID-HISTORICAL',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('ALTER TABLE work_schedules ENABLE TRIGGER work_schedules_require_seven_days');

    $migration = require database_path('migrations/2026_10_07_215726_repair_work_schedule_integrity_trigger.php');
    $migration->down();

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, '1 existing schedules do not have exactly seven day records');

    expect(DB::table('work_schedules')->where('id', $scheduleId)->exists())->toBeTrue()
        ->and(DB::table('work_schedule_days')->where('work_schedule_id', $scheduleId)->count())->toBe(0);
});

it('installs the repaired function after rollback and preserves deferred trigger definitions', function (): void {
    $migration = require database_path('migrations/2026_10_07_215726_repair_work_schedule_integrity_trigger.php');
    $migration->down();
    $oldDefinition = DB::scalar("SELECT pg_get_functiondef('public.enforce_complete_work_schedule_days()'::regprocedure)");

    expect($oldDefinition)->toContain('OLD.work_schedule_id');

    $migration->up();
    $functionDefinition = DB::scalar("SELECT pg_get_functiondef('public.enforce_complete_work_schedule_days()'::regprocedure)");
    $triggerRows = collect(DB::select("SELECT trig.tgname, relation.relname, pg_get_triggerdef(trig.oid, true) AS definition, trig.tgdeferrable, trig.tginitdeferred, trig.tgenabled FROM pg_trigger AS trig JOIN pg_class AS relation ON relation.oid = trig.tgrelid WHERE trig.tgname IN ('work_schedules_require_seven_days', 'work_schedule_days_require_seven_days') ORDER BY trig.tgname"));

    expect($functionDefinition)->toContain('TG_TABLE_NAME = \'work_schedule_days\'')
        ->and($triggerRows)->toHaveCount(2)
        ->and($triggerRows->every(fn ($trigger): bool => $trigger->tgdeferrable && $trigger->tginitdeferred && $trigger->tgenabled === 'O'))->toBeTrue()
        ->and($triggerRows->firstWhere('tgname', 'work_schedules_require_seven_days')->definition)->toContain('AFTER INSERT ON work_schedules')
        ->and($triggerRows->firstWhere('tgname', 'work_schedule_days_require_seven_days')->definition)->toContain('AFTER INSERT OR DELETE OR UPDATE ON work_schedule_days');
});

/** @return array{Company, User, Shift} */
function postgresWorkScheduleFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $shift = Shift::factory()->create(['company_id' => $company->getKey()]);

    return [$company, $actor, $shift];
}

/** @param array<string, mixed> $overrides
 * @return array{name: string, code: string, status: string, days: list<array{day_of_week: int, day_type: string, shift_id: int|null}>}
 */
function postgresWorkSchedulePayload(Shift $shift, array $overrides = []): array
{
    $days = [];
    foreach (range(1, 7) as $dayOfWeek) {
        $working = $dayOfWeek <= 5;
        $days[] = [
            'day_of_week' => $dayOfWeek,
            'day_type' => $working ? WorkScheduleDayType::Working->value : WorkScheduleDayType::Off->value,
            'shift_id' => $working ? $shift->getKey() : null,
        ];
    }

    return array_merge([
        'name' => 'PostgreSQL schedule',
        'code' => 'POSTGRES-SCHEDULE',
        'status' => 'active',
        'days' => $days,
    ], $overrides);
}
