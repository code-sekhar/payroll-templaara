<?php

use App\Actions\AttendanceRegularizations\CreateAttendanceRegularizationRequest;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularizationRequest;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') !== '1') {
        $this->markTestSkipped('Set EP4_POSTGRES_TESTING=1 to run destructive PostgreSQL tests against the isolated EP4 test database.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'payroll_ep4_hardening_test') {
        throw new LogicException('EP4 PostgreSQL tests may only use the payroll_ep4_hardening_test database.');
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

it('allows punch inserts but rejects model query-builder and raw SQL mutation', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'notes' => 'original',
    ]);

    expect(fn () => $punch->update(['notes' => 'eloquent edit']))->toThrow(LogicException::class)
        ->and(fn () => DB::table('attendance_punches')->where('id', $punch->getKey())->update(['notes' => 'builder edit']))->toThrow(QueryException::class)
        ->and(fn () => DB::update('UPDATE attendance_punches SET notes = ? WHERE id = ?', ['sql edit', $punch->getKey()]))->toThrow(QueryException::class)
        ->and(fn () => $punch->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('attendance_punches')->where('id', $punch->getKey())->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM attendance_punches WHERE id = ?', [$punch->getKey()]))->toThrow(QueryException::class);

    expect(DB::table('attendance_punches')->where('id', $punch->getKey())->value('notes'))->toBe('original')
        ->and(DB::table('attendance_punches')->where('id', $punch->getKey())->count())->toBe(1);
});

it('rejects non-finite punch location accuracy and distance values at the database', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
    ]);
    $row = $punch->getAttributes();
    unset($row['id']);

    foreach ([
        ['location_accuracy_meters' => 'NaN'],
        ['location_accuracy_meters' => 'Infinity'],
        ['location_distance_meters' => 'NaN'],
        ['location_distance_meters' => 'Infinity'],
    ] as $invalidValues) {
        expect(fn () => DB::table('attendance_punches')->insert([...$row, ...$invalidValues]))
            ->toThrow(QueryException::class);
    }

    expect(DB::table('attendance_punches')->where('employee_id', $employee->getKey())->count())->toBe(1);
});

it('enforces punch and worksite coordinate and radius bounds in PostgreSQL', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
    ]);
    $punchRow = $punch->getAttributes();
    unset($punchRow['id']);

    foreach ([
        ['latitude' => '91'],
        ['latitude' => 'NaN'],
        ['longitude' => '-181'],
        ['longitude' => 'Infinity'],
    ] as $invalidValues) {
        expect(fn () => DB::table('attendance_punches')->insert([...$punchRow, ...$invalidValues]))
            ->toThrow(QueryException::class);
    }

    $worksite = AttendanceWorksite::factory()->create(['company_id' => $company->getKey()]);
    $worksiteRow = $worksite->getAttributes();
    unset($worksiteRow['id']);

    foreach ([
        ['latitude' => '91'],
        ['longitude' => '-181'],
        ['radius_meters' => 0],
        ['radius_meters' => 10001],
    ] as $invalidValues) {
        expect(fn () => DB::table('attendance_worksites')->insert([
            ...$worksiteRow,
            'name' => fake()->unique()->company().' Workplace',
            ...$invalidValues,
        ]))->toThrow(QueryException::class);
    }
});

it('rejects a regularization daily record owned by another employee or date', function (): void {
    $company = Company::factory()->create();
    $firstEmployee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $secondEmployee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $attendanceDate = '2026-10-06';
    $record = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $firstEmployee->getKey(),
        'attendance_date' => $attendanceDate,
    ]);
    $actor = User::factory()->create();

    expect(fn () => DB::table('attendance_regularization_requests')->insert([
        'company_id' => $company->getKey(),
        'employee_id' => $secondEmployee->getKey(),
        'daily_attendance_record_id' => $record->getKey(),
        'attendance_date' => $attendanceDate,
        'request_type' => 'other',
        'status' => 'pending',
        'reason' => 'A sufficiently detailed reason.',
        'requested_by' => $actor->getKey(),
        'requested_at' => now('UTC'),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(DB::table('attendance_regularization_requests')->count())->toBe(0);
});

it('serializes concurrent punch-in and punch-out requests with independent PostgreSQL sessions', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create(['timezone' => 'Asia/Kolkata']);
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $actor = User::factory()->create();
    $punchInResults = runAttendancePostgresRace('employees', (int) $employee->getKey(), $company, $employee, $actor, AttendancePunchType::CheckIn);

    expect(array_count_values($punchInResults))->toMatchArray(['success' => 1, 'validation' => 1])
        ->and(AttendancePunch::query()->where('employee_id', $employee->getKey())->where('punch_type', AttendancePunchType::CheckIn)->count())->toBe(1);

    $punchOutResults = runAttendancePostgresRace('employees', (int) $employee->getKey(), $company, $employee, $actor, AttendancePunchType::CheckOut);

    expect(array_count_values($punchOutResults))->toMatchArray(['success' => 1, 'validation' => 1])
        ->and(AttendancePunch::query()->where('employee_id', $employee->getKey())->where('punch_type', AttendancePunchType::CheckOut)->count())->toBe(1)
        ->and(AttendancePunch::query()->where('employee_id', $employee->getKey())->count())->toBe(2);
});

it('serializes concurrent regularization creation and review decisions with independent PostgreSQL sessions', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $attendanceDate = now()->subDay()->toDateString();

    $createResults = runAttendancePostgresRace(
        'employees',
        (int) $employee->getKey(),
        $company,
        $employee,
        $requester,
        operation: 'regularization_create',
        attendanceDate: $attendanceDate,
    );

    expect(array_count_values($createResults))->toMatchArray(['success' => 1, 'validation' => 1])
        ->and(AttendanceRegularizationRequest::query()->where('employee_id', $employee->getKey())->where('status', 'pending')->count())->toBe(1);

    $request = AttendanceRegularizationRequest::query()->where('employee_id', $employee->getKey())->firstOrFail();
    $reviewResults = runAttendancePostgresRace(
        'attendance_regularization_requests',
        (int) $request->getKey(),
        $company,
        $employee,
        $reviewer,
        operation: 'regularization_review',
        requestId: (int) $request->getKey(),
    );
    $request->refresh();

    expect(array_count_values($reviewResults))->toMatchArray(['success' => 1, 'validation' => 1])
        ->and($request->status->value)->toBeIn(['approved', 'rejected'])
        ->and($request->reviewed_by)->toBe($reviewer->getKey())
        ->and(DB::table('attendance_punch_corrections')->where('attendance_regularization_request_id', $request->getKey())->count())->toBe($request->status->value === 'approved' ? 1 : 0);
});

it('allows only one terminal outcome in approve-approve approve-cancel and reject-cancel races', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();

    foreach ([
        ['regularization_approve_both', 'approved', 1],
        ['regularization_approve_cancel', ['approved', 'cancelled'], null],
        ['regularization_reject_cancel', ['rejected', 'cancelled'], 0],
    ] as $index => [$operation, $expectedStatus, $expectedCorrectionCount]) {
        $attendanceDate = now()->subDays($index + 2)->toDateString();
        $request = app(CreateAttendanceRegularizationRequest::class)->handle(
            $company,
            $employee,
            $requester,
            [
                'attendance_date' => $attendanceDate,
                'request_type' => 'missing_check_in',
                'reason' => 'A sufficiently detailed concurrency test reason.',
                'items' => [[
                    'action' => 'add',
                    'punch_type' => 'check_in',
                    'requested_punched_at' => $attendanceDate.' 09:00:00',
                ]],
            ],
        );

        $results = runAttendancePostgresRace(
            'attendance_regularization_requests',
            (int) $request->getKey(),
            $company,
            $employee,
            $reviewer,
            operation: $operation,
            requestId: (int) $request->getKey(),
        );
        $request->refresh();
        $correctionCount = DB::table('attendance_punch_corrections')
            ->where('attendance_regularization_request_id', $request->getKey())
            ->count();

        expect(array_count_values($results))->toMatchArray(['success' => 1, 'validation' => 1]);

        if (is_array($expectedStatus)) {
            expect($request->status->value)->toBeIn($expectedStatus);
        } else {
            expect($request->status->value)->toBe($expectedStatus);
        }

        if ($expectedCorrectionCount !== null) {
            expect($correctionCount)->toBe($expectedCorrectionCount);
        } else {
            expect($correctionCount)->toBe($request->status->value === 'approved' ? 1 : 0);
        }
    }
});

it('keeps concurrent materialization to one daily record and one initial history row', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for separate-process PostgreSQL concurrency verification.');
    }

    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $actor = User::factory()->create();
    $attendanceDate = now()->subDay()->toDateString();
    $results = runAttendancePostgresRace(
        'employees',
        (int) $employee->getKey(),
        $company,
        $employee,
        $actor,
        operation: 'materialize',
        attendanceDate: $attendanceDate,
    );

    expect($results)->toBe(['success', 'success'])
        ->and(DB::table('daily_attendance_records')->where('employee_id', $employee->getKey())->where('attendance_date', $attendanceDate)->count())->toBe(1)
        ->and(DB::table('daily_attendance_record_histories')->where('employee_id', $employee->getKey())->where('attendance_date', $attendanceDate)->count())->toBe(1);
});

it('removes only its attendance hardening objects on rollback and reinstalls them cleanly', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $punch = AttendancePunch::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'notes' => 'preserve across migration rollback',
    ]);

    $attendanceIntegrityMigration = require database_path('migrations/2026_10_07_164113_harden_attendance_punch_and_regularization_integrity.php');
    $attendanceIntegrityMigration->down();

    expect(DB::table('attendance_punches')->where('id', $punch->getKey())->value('notes'))
        ->toBe('preserve across migration rollback')
        ->and(DB::table('pg_trigger')->where('tgname', 'attendance_punches_prevent_mutation')->exists())->toBeFalse()
        ->and(DB::table('pg_trigger')->where('tgname', 'work_schedules_require_seven_days')->exists())->toBeTrue();

    $attendanceIntegrityMigration->up();

    expect(DB::table('pg_trigger')->where('tgname', 'attendance_punches_prevent_mutation')->exists())->toBeTrue()
        ->and(DB::table('pg_trigger')->where('tgname', 'work_schedules_require_seven_days')->exists())->toBeTrue()
        ->and(DB::table('attendance_punches')->where('id', $punch->getKey())->value('notes'))
        ->toBe('preserve across migration rollback');
});

/**
 * @return list<string>
 */
function runAttendancePostgresRace(
    string $lockTable,
    int $lockId,
    Company $company,
    Employee $employee,
    User $actor,
    ?AttendancePunchType $punchType = null,
    string $operation = 'punch',
    ?string $attendanceDate = null,
    ?int $requestId = null,
): array {
    if (! in_array($lockTable, ['employees', 'attendance_regularization_requests'], true)) {
        throw new InvalidArgumentException('The PostgreSQL race helper does not accept arbitrary lock table names.');
    }

    $connection = DB::connection();
    $connection->beginTransaction();
    $connection->select("SELECT id FROM {$lockTable} WHERE id = ? FOR UPDATE", [$lockId]);

    if (! $connection->getPdo()->inTransaction()) {
        $connection->rollBack();
        throw new RuntimeException('The PostgreSQL concurrency gate did not retain its employee row lock in a transaction.');
    }

    $token = bin2hex(random_bytes(5));
    $workers = [];

    for ($index = 0; $index < 2; $index++) {
        $applicationName = "ep4_{$token}_{$index}";

        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'payroll_ep4_hardening_test',
            'EP4_PG_WORKER_APPLICATION_NAME' => $applicationName,
            'EP4_PG_COMPANY_ID' => (string) $company->getKey(),
            'EP4_PG_EMPLOYEE_ID' => (string) $employee->getKey(),
            'EP4_PG_ACTOR_ID' => (string) $actor->getKey(),
            'EP4_PG_OPERATION' => match ($operation) {
                'regularization_review' => $index === 0 ? 'regularization_approve' : 'regularization_reject',
                'regularization_approve_both' => 'regularization_approve',
                'regularization_approve_cancel' => $index === 0 ? 'regularization_approve' : 'regularization_cancel',
                'regularization_reject_cancel' => $index === 0 ? 'regularization_reject' : 'regularization_cancel',
                default => $operation,
            },
            'EP4_PG_PUNCH_TYPE' => $punchType?->value ?? '',
            'EP4_PG_ATTENDANCE_DATE' => $attendanceDate ?? '',
            'EP4_PG_REQUEST_ID' => (string) ($requestId ?? ''),
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Postgres/attendance-punch-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($process)) {
            $connection->rollBack();
            throw new RuntimeException('Unable to create a PostgreSQL concurrency worker process.');
        }

        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }

    foreach ($workers as $worker) {
        $ready = trim((string) fgets($worker['pipes'][1]));
        if (! str_starts_with($ready, 'ready:payroll_ep4_hardening_test:')) {
            $connection->rollBack();
            throw new RuntimeException('A PostgreSQL worker failed to establish its independent test database connection.');
        }
    }

    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "go\n");
        fflush($worker['pipes'][0]);
        fclose($worker['pipes'][0]);
    }

    $deadline = microtime(true) + 8;
    $blockedWorkers = 0;
    $workerActivity = [];

    do {
        $workerActivity = DB::table('pg_stat_activity')
            ->where('application_name', 'like', 'ep4_%')
            ->get(['datname', 'application_name', 'state', 'wait_event_type', 'wait_event', 'query'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $blockedWorkers = DB::table('pg_stat_activity')
            ->where('datname', 'payroll_ep4_hardening_test')
            ->where('pid', '<>', DB::raw('pg_backend_pid()'))
            ->where('wait_event_type', 'Lock')
            ->count();

        if ($blockedWorkers === 2) {
            break;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    $connection->commit();

    $results = [];
    foreach ($workers as $worker) {
        $output = stream_get_contents($worker['pipes'][1]);
        $errorOutput = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $outputLines = array_values(array_filter(explode("\n", trim((string) $output))));
        $resultLine = $outputLines[array_key_last($outputLines)] ?? '';
        $result = explode(':', $resultLine, 2)[0];
        $results[] = $exitCode === 0 && in_array($result, ['success', 'validation'], true)
            ? $result
            : 'error:'.($result !== '' ? $result.' '.trim((string) $errorOutput) : trim((string) $errorOutput));
    }

    if ($blockedWorkers !== 2) {
        throw new RuntimeException("Expected two independent workers to wait on the employee lock; observed {$blockedWorkers}. Worker results: ".implode(', ', $results).'; activity: '.json_encode($workerActivity));
    }

    return $results;
}
