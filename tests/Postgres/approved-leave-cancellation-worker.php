<?php

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\CancelApprovedLeaveRequest;
use App\Actions\LeaveRequests\CancelLeaveRequest;
use App\Actions\LeaveRequests\RejectLeaveRequest;
use App\Models\Company;
use App\Models\LeaveRequest;
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
        throw new LogicException('Approved Leave cancellation workers may only use the isolated PostgreSQL test database.');
    }

    DB::selectOne('SELECT set_config(?, ?, false)', [
        'application_name',
        (string) getenv('EP5_CANCEL_PG_WORKER_APPLICATION_NAME'),
    ]);
    fwrite(STDOUT, 'ready:'.$databaseName."\n");
    fgets(STDIN);

    $leaveRequest = LeaveRequest::query()->findOrFail((int) getenv('EP5_CANCEL_PG_REQUEST_ID'));
    $company = Company::query()->findOrFail((int) $leaveRequest->company_id);
    $actor = User::query()->findOrFail((int) getenv('EP5_CANCEL_PG_ACTOR_ID'));
    $operation = (string) getenv('EP5_CANCEL_PG_OPERATION');
    if ($operation === 'pending_cancel') {
        app(CancelLeaveRequest::class)->handle($leaveRequest, $actor, 'Plans changed');
        fwrite(STDOUT, 'pending_cancelled:'.$databaseName."\n");

        return;
    }

    if ($operation === 'approve') {
        app(ApproveLeaveRequest::class)->handle($leaveRequest, $actor, $company);
        fwrite(STDOUT, 'approved:'.$databaseName."\n");

        return;
    }

    if ($operation === 'reject') {
        app(RejectLeaveRequest::class)->handle($leaveRequest, $actor, $company, 'Rejected in PostgreSQL concurrency test');
        fwrite(STDOUT, 'rejected:'.$databaseName."\n");

        return;
    }

    $result = app(CancelApprovedLeaveRequest::class)->execute($leaveRequest, $actor, 'No longer required');

    fwrite(STDOUT, ($result->wasCancelledNow ? 'cancelled' : 'already_cancelled').':'.$databaseName."\n");
} catch (ValidationException) {
    fwrite(STDOUT, 'validation:'.(string) config('database.connections.pgsql.database')."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
