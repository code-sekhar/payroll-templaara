<?php

namespace App\Console\Commands;

use App\Actions\LeaveBalances\FinalizeLeavePeriod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Exceptions\Leave\InvalidLeaveAccrualConfigurationException;
use App\Exceptions\Leave\InvalidLeavePeriodClosingConfigurationException;
use App\Exceptions\Leave\LeavePeriodClosingAccountingException;
use App\Exceptions\Leave\LeavePeriodFinalizationException;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class CloseLeavePeriods extends Command
{
    private const CHUNK_SIZE = 100;

    protected $signature = 'leave:close-periods {--company=} {--as-of=} {--account=}';

    protected $description = 'Finalize ended leave balance periods for one company';

    public function handle(FinalizeLeavePeriod $finalizer): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $companyId) {
            $this->error('A valid --company ID is required.');

            return self::INVALID;
        }

        $company = Company::query()->find($companyId);
        if (! $company) {
            $this->error('The specified company does not exist.');

            return self::INVALID;
        }

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            $this->error('The selected company has an invalid timezone configuration.');

            return self::INVALID;
        }

        $asOfDate = $this->resolveAsOfDate($timezone);
        if (! $asOfDate) {
            return self::INVALID;
        }

        $accountId = null;
        if ($this->option('account') !== null) {
            $accountId = filter_var($this->option('account'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! $accountId) {
                $this->error('A valid --account ID is required when the option is supplied.');

                return self::INVALID;
            }
        }

        $counts = [
            'scanned' => 0,
            'processed' => 0,
            'finalized' => 0,
            'already_finalized' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        if ($accountId !== null) {
            $account = $company->leaveBalanceAccounts()
                ->with(['employee', 'leaveType'])
                ->whereKey($accountId)
                ->first();

            if (! $account) {
                $this->error('The specified account is unavailable for this company.');

                return self::INVALID;
            }

            $counts['scanned']++;
            $this->processAccount($account, $asOfDate, $finalizer, $counts);
        } else {
            $company->leaveBalanceAccounts()
                ->with(['employee', 'leaveType'])
                ->where('status', LeaveBalanceAccountStatus::Open->value)
                ->where('period_end', '<', $asOfDate->addDay()->toDateString())
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($accounts) use ($asOfDate, $finalizer, &$counts): void {
                    foreach ($accounts as $account) {
                        $counts['scanned']++;
                        $this->processAccount($account, $asOfDate, $finalizer, $counts);
                    }
                });
        }

        $this->newLine();
        $this->info("Company: {$company->name}");
        $this->line("As of: {$asOfDate->toDateString()}");
        $this->line("Scanned: {$counts['scanned']}");
        $this->line("Processed: {$counts['processed']}");
        $this->line("Finalized: {$counts['finalized']}");
        $this->line("Already finalized: {$counts['already_finalized']}");
        $this->line("Skipped: {$counts['skipped']}");
        $this->line("Failed: {$counts['failed']}");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveAsOfDate(string $timezone): ?CarbonImmutable
    {
        $dateInput = $this->option('as-of');
        if ($dateInput === null) {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        if (! is_string($dateInput) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            $this->error('The --as-of date must use strict YYYY-MM-DD format.');

            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateInput, $timezone);
            $errors = CarbonImmutable::getLastErrors();
        } catch (Throwable) {
            $date = false;
            $errors = ['warning_count' => 1, 'error_count' => 1];
        }

        if (! $date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $dateInput) {
            $this->error('The specified --as-of date is not a valid calendar date.');

            return null;
        }

        return $date;
    }

    /**
     * @param  array{scanned: int, processed: int, finalized: int, already_finalized: int, skipped: int, failed: int}  $counts
     */
    private function processAccount(
        EmployeeLeaveBalanceAccount $account,
        CarbonImmutable $asOfDate,
        FinalizeLeavePeriod $finalizer,
        array &$counts,
    ): void {
        $employeeCode = str_replace(["\r", "\n"], ' ', $account->employee?->employee_code ?: '#'.$account->employee_id);
        $leaveTypeName = str_replace(["\r", "\n"], ' ', $account->leaveType?->name ?: 'Leave Type #'.$account->leave_type_id);
        $label = sprintf(
            'account=%s employee=%s leave_type="%s" period=%s..%s',
            $account->getKey(),
            $employeeCode,
            $leaveTypeName,
            $account->period_start->toDateString(),
            $account->period_end->toDateString(),
        );

        if ($account->period_end->toDateString() > $asOfDate->toDateString()) {
            $counts['skipped']++;
            $this->line("SKIPPED {$label} reason=PERIOD_NOT_ENDED");

            return;
        }

        $counts['processed']++;

        try {
            $result = $finalizer->execute($account, $asOfDate);

            if ($result->wasClosedNow) {
                $counts['finalized']++;
                $this->line("FINALIZED {$label} carry={$result->carryForwardAmount} expiry={$result->expiryAmount}");

                return;
            }

            $counts['already_finalized']++;
            $this->line("ALREADY FINALIZED {$label}");
        } catch (Throwable $exception) {
            [$classification, $reason] = $this->classifyFailure($exception);
            $counts[$classification]++;
            $output = strtoupper($classification);
            if ($classification === 'skipped') {
                $this->line("{$output} {$label} reason={$reason}");
            } else {
                $this->error("{$output} {$label} reason={$reason}");
            }
        }
    }

    /** @return array{'skipped'|'failed', string} */
    private function classifyFailure(Throwable $exception): array
    {
        if ($exception instanceof LeavePeriodFinalizationException) {
            if ($exception->reason === 'CLOSING_NOT_ELIGIBLE' && $exception->blockers !== []) {
                $skippableBlockers = [
                    'PERIOD_NOT_ENDED',
                    'ACCRUAL_NOT_FULLY_RECONCILED',
                    'PENDING_LEAVE_RESERVATIONS',
                    'NEGATIVE_CLOSING_BALANCE',
                    'UNSUPPORTED_ACCRUAL_CONFIGURATION',
                    'NEXT_PERIOD_ACCOUNT_CONFLICT',
                ];

                if (array_diff($exception->blockers, $skippableBlockers) === []) {
                    return ['skipped', implode(',', $exception->blockers)];
                }

                return ['failed', implode(',', $exception->blockers)];
            }

            if (in_array($exception->reason, [
                'NEXT_PERIOD_ACCOUNT_REQUIRED',
                'NEXT_PERIOD_ACCOUNT_CLOSED',
                'BALANCE_CAP_EXCEEDED',
                'NEGATIVE_BALANCE_FLOOR_EXCEEDED',
            ], true)) {
                return ['skipped', $exception->reason];
            }

            return ['failed', $exception->reason];
        }

        if ($exception instanceof LeavePeriodClosingAccountingException) {
            if (in_array($exception->reason, ['NEXT_PERIOD_ACCOUNT_REQUIRED', 'NEXT_PERIOD_ACCOUNT_CLOSED', 'BALANCE_CAP_EXCEEDED'], true)) {
                return ['skipped', $exception->reason];
            }

            return ['failed', $exception->reason];
        }

        if ($exception instanceof InvalidLeavePeriodClosingConfigurationException
            || $exception instanceof InvalidLeaveAccrualConfigurationException) {
            if ($exception instanceof InvalidLeaveAccrualConfigurationException
                && in_array($exception->reason, ['UNSUPPORTED_ACCRUAL_FREQUENCY', 'UNSUPPORTED_ACCRUAL_METHOD'], true)) {
                return ['skipped', $exception->reason];
            }

            return ['failed', $exception->reason];
        }

        return ['failed', 'UNEXPECTED_ERROR'];
    }
}
