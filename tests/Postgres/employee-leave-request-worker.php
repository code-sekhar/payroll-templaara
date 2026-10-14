<?php

use App\Actions\LeaveRequests\CreateLeaveRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $databaseName = (string) config('database.connections.pgsql.database');
    if ($databaseName !== 'payroll_ep4_hardening_test') {
        throw new LogicException('Employee Leave concurrency workers may only use the isolated PostgreSQL test database.');
    }

    DB::selectOne('SELECT set_config(?, ?, false)', [
        'application_name',
        (string) getenv('EP5_PG_WORKER_APPLICATION_NAME'),
    ]);
    fwrite(STDOUT, 'ready:'.$databaseName."\n");
    fgets(STDIN);

    $company = Company::query()->findOrFail((int) getenv('EP5_PG_COMPANY_ID'));
    $employee = Employee::query()->findOrFail((int) getenv('EP5_PG_EMPLOYEE_ID'));
    $leaveType = LeaveType::query()->findOrFail((int) getenv('EP5_PG_LEAVE_TYPE_ID'));
    $actor = User::query()->findOrFail((int) getenv('EP5_PG_ACTOR_ID'));

    app(CreateLeaveRequest::class)->handle(
        $employee,
        $leaveType,
        (string) getenv('EP5_PG_LEAVE_DATE'),
        (string) getenv('EP5_PG_LEAVE_DATE'),
        'full_day',
        'Concurrent submission verification',
        null,
        $actor,
    );

    fwrite(STDOUT, 'success:'.$databaseName."\n");
} catch (ValidationException) {
    fwrite(STDOUT, 'validation:'.(string) config('database.connections.pgsql.database')."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
