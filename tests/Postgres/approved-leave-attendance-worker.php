<?php

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\ApprovedLeaveAttendanceSynchronizer;
use App\Services\Attendance\DailyAttendanceMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $databaseName = (string) config('database.connections.pgsql.database');
    if ($databaseName !== 'payroll_ep4_hardening_test') {
        throw new LogicException('Approved Leave Attendance workers may only use the isolated PostgreSQL test database.');
    }

    DB::selectOne('SELECT set_config(?, ?, false)', [
        'application_name',
        (string) getenv('EP5_APPROVED_ATTENDANCE_WORKER_APPLICATION_NAME'),
    ]);
    fwrite(STDOUT, 'ready:'.$databaseName."\n");
    fgets(STDIN);

    $request = LeaveRequest::query()->findOrFail((int) getenv('EP5_APPROVED_ATTENDANCE_REQUEST_ID'));
    $operation = (string) getenv('EP5_APPROVED_ATTENDANCE_OPERATION');

    if ($operation === 'approve') {
        $reviewer = User::query()->findOrFail((int) getenv('EP5_APPROVED_ATTENDANCE_REVIEWER_ID'));
        $company = Company::query()->findOrFail((int) $request->company_id);
        app(ApproveLeaveRequest::class)->handle($request, $reviewer, $company);
        fwrite(STDOUT, 'approved:'.$databaseName."\n");

        return;
    }

    if ($operation === 'synchronize') {
        app(ApprovedLeaveAttendanceSynchronizer::class)->synchronize($request);
        fwrite(STDOUT, 'synchronized:'.$databaseName."\n");

        return;
    }

    if ($operation === 'materialize') {
        $employee = Employee::withTrashed()
            ->where('company_id', $request->company_id)
            ->whereKey($request->employee_id)
            ->firstOrFail();
        $timezone = $employee->company?->timezone ?: config('app.timezone', 'UTC');
        app(DailyAttendanceMaterializer::class)->materialize(
            $employee,
            CarbonImmutable::parse((string) getenv('EP5_APPROVED_ATTENDANCE_DATE'), $timezone),
        );
        fwrite(STDOUT, 'materialized:'.$databaseName."\n");

        return;
    }

    throw new LogicException('Unknown PostgreSQL Attendance worker operation.');
} catch (ValidationException) {
    fwrite(STDOUT, 'validation:'.(string) config('database.connections.pgsql.database')."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
