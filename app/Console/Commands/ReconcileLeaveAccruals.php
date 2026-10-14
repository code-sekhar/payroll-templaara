<?php

namespace App\Console\Commands;

use App\Actions\LeaveBalances\ReconcileLeaveAccrual;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveBalanceAccountStatus;
use App\Exceptions\Leave\InvalidLeaveAccrualConfigurationException;
use App\Exceptions\Leave\LeaveAccrualReconciliationException;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Services\Leave\LeaveAccrualReconciliationResult;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class ReconcileLeaveAccruals extends Command
{
    private const CHUNK_SIZE = 100;

    protected $signature = 'leave:reconcile-accruals {--company=} {--as-of=} {--account=}';

    protected $description = 'Reconcile accrued leave entitlement for open balance accounts in one company';

    public function handle(ReconcileLeaveAccrual $reconciliation): int
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
            'posted' => 0,
            'up_to_date' => 0,
            'skipped' => 0,
            'failed' => 0,
            'posted_cents' => 0,
        ];

        if ($accountId !== null) {
            $account = $company->leaveBalanceAccounts()
                ->with(['employee', 'leaveType', 'leavePolicy'])
                ->whereKey($accountId)
                ->first();
            if (! $account) {
                $this->error('The specified account is unavailable for this company.');

                return self::INVALID;
            }

            $counts['scanned']++;
            $this->processAccount($account, $asOfDate, $reconciliation, $counts, explicitlySelected: true);
        } else {
            $company->leaveBalanceAccounts()
                ->with(['employee', 'leaveType', 'leavePolicy'])
                ->where('status', LeaveBalanceAccountStatus::Open->value)
                ->whereDate('period_start', '<=', $asOfDate->toDateString())
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($accounts) use ($asOfDate, $reconciliation, &$counts): void {
                    foreach ($accounts as $account) {
                        $counts['scanned']++;
                        $this->processAccount($account, $asOfDate, $reconciliation, $counts);
                    }
                });
        }

        $this->newLine();
        $this->info("Company: {$company->name}");
        $this->line("As of: {$asOfDate->toDateString()}");
        $this->line("Scanned: {$counts['scanned']}");
        $this->line("Processed: {$counts['processed']}");
        $this->line("Posted: {$counts['posted']}");
        $this->line("Up to date: {$counts['up_to_date']}");
        $this->line("Skipped: {$counts['skipped']}");
        $this->line("Failed: {$counts['failed']}");
        $this->line('Accrued now: '.LeaveAmount::fromCents($counts['posted_cents']));

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

        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $dateInput) {
            $this->error('The specified --as-of date is not a valid calendar date.');

            return null;
        }

        return $date;
    }

    /** @param array{scanned: int, processed: int, posted: int, up_to_date: int, skipped: int, failed: int, posted_cents: int} $counts */
    private function processAccount(
        EmployeeLeaveBalanceAccount $account,
        CarbonImmutable $asOfDate,
        ReconcileLeaveAccrual $reconciliation,
        array &$counts,
        bool $explicitlySelected = false,
    ): void {
        $employee = $account->employee;
        $leaveType = $account->leaveType;
        $label = sprintf(
            'account #%s / Employee %s / %s',
            $account->getKey(),
            $employee?->employee_code ?: '#'.$account->employee_id,
            $leaveType?->name ?: 'Leave Type #'.$account->leave_type_id,
        );

        if ($explicitlySelected && $account->status !== LeaveBalanceAccountStatus::Open) {
            $counts['failed']++;
            $this->error("FAILED {$label}; BALANCE_ACCOUNT_CLOSED");

            return;
        }

        if ($account->period_start->toDateString() > $asOfDate->toDateString()) {
            $counts['skipped']++;
            $this->line("SKIPPED {$label}; FUTURE_ACCOUNT_PERIOD");

            return;
        }

        $method = $account->leavePolicy?->getRawOriginal('accrual_method');
        if ($method === LeaveAccrualMethod::None->value) {
            $counts['skipped']++;
            $this->line("SKIPPED {$label}; NO_AUTOMATIC_ACCRUAL");

            return;
        }

        try {
            /** @var LeaveAccrualReconciliationResult $result */
            $result = $reconciliation->execute($account, $asOfDate);
            $counts['processed']++;

            if ($result->wasPosted) {
                $counts['posted']++;
                $counts['posted_cents'] += LeaveAmount::toCents($result->postedAmount);
                $this->line("POSTED {$label}: {$result->postedAmount}");
            } else {
                $counts['up_to_date']++;
                $this->line("UP TO DATE {$label}");
            }
        } catch (Throwable $exception) {
            $counts['failed']++;
            $reason = match (true) {
                $exception instanceof InvalidLeaveAccrualConfigurationException => $exception->reason,
                $exception instanceof LeaveAccrualReconciliationException => $exception->reason,
                default => 'UNEXPECTED_ERROR',
            };

            $this->error("FAILED {$label}; {$reason}");
        }
    }
}
