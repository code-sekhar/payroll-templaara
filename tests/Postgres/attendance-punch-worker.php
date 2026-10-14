<?php

use App\Actions\AttendancePunches\CreateWebAttendancePunch;
use App\Actions\AttendanceRegularizations\ApproveAttendanceRegularizationRequest;
use App\Actions\AttendanceRegularizations\CancelAttendanceRegularizationRequest;
use App\Actions\AttendanceRegularizations\CreateAttendanceRegularizationRequest;
use App\Actions\AttendanceRegularizations\RejectAttendanceRegularizationRequest;
use App\Enums\AttendancePunchType;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\DailyAttendanceMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    DB::selectOne('SELECT set_config(?, ?, false)', [
        'application_name',
        (string) getenv('EP4_PG_WORKER_APPLICATION_NAME'),
    ]);

    $backendPid = DB::selectOne('SELECT pg_backend_pid() AS id')->id;
    fwrite(STDOUT, 'ready:'.DB::connection()->getDatabaseName().':'.$backendPid."\n");
    fgets(STDIN);

    $company = Company::query()->findOrFail((int) getenv('EP4_PG_COMPANY_ID'));
    $employee = Employee::query()->findOrFail((int) getenv('EP4_PG_EMPLOYEE_ID'));
    $actor = User::query()->findOrFail((int) getenv('EP4_PG_ACTOR_ID'));

    match ((string) getenv('EP4_PG_OPERATION')) {
        'punch' => app(CreateWebAttendancePunch::class)->handle(
            $company,
            $employee,
            $actor,
            AttendancePunchType::from((string) getenv('EP4_PG_PUNCH_TYPE')),
            [],
            null,
            null,
        ),
        'regularization_create' => app(CreateAttendanceRegularizationRequest::class)->handle(
            $company,
            $employee,
            $actor,
            [
                'attendance_date' => (string) getenv('EP4_PG_ATTENDANCE_DATE'),
                'request_type' => 'missing_check_in',
                'reason' => 'A sufficiently detailed concurrency test reason.',
                'items' => [[
                    'action' => 'add',
                    'punch_type' => 'check_in',
                    'requested_punched_at' => (string) getenv('EP4_PG_ATTENDANCE_DATE').' 09:00:00',
                ]],
            ],
        ),
        'regularization_approve' => app(ApproveAttendanceRegularizationRequest::class)->handle(
            AttendanceRegularizationRequest::query()->findOrFail((int) getenv('EP4_PG_REQUEST_ID')),
            $actor,
            'Concurrency test approval.',
        ),
        'regularization_reject' => app(RejectAttendanceRegularizationRequest::class)->handle(
            AttendanceRegularizationRequest::query()->findOrFail((int) getenv('EP4_PG_REQUEST_ID')),
            $actor,
            'Concurrency test rejection.',
        ),
        'regularization_cancel' => app(CancelAttendanceRegularizationRequest::class)->handle(
            AttendanceRegularizationRequest::query()->findOrFail((int) getenv('EP4_PG_REQUEST_ID')),
        ),
        'materialize' => app(DailyAttendanceMaterializer::class)->materialize(
            $employee,
            CarbonImmutable::parse((string) getenv('EP4_PG_ATTENDANCE_DATE')),
            $actor,
        ),
        default => throw new InvalidArgumentException('Unsupported PostgreSQL test worker operation.'),
    };

    fwrite(STDOUT, 'success:'.DB::connection()->getDatabaseName()."\n");
} catch (ValidationException) {
    fwrite(STDOUT, 'validation:'.DB::connection()->getDatabaseName()."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
