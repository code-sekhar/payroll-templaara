<?php

namespace App\Console\Commands;

use App\Enums\EmploymentStatus;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Services\Attendance\DailyAttendanceMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class MaterializeAttendance extends Command
{
    protected $signature = 'attendance:materialize {--company=} {--date=}';

    protected $description = 'Materialize attendance records for active employees in one company on one date';

    public function handle(DailyAttendanceMaterializer $materializer): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $dateInput = $this->option('date');

        if (! $companyId || ! is_string($dateInput) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            $this->error('Both --company and --date=YYYY-MM-DD are required.');

            return self::INVALID;
        }

        $company = Company::query()->find($companyId);
        if (! $company) {
            $this->error('The specified company does not exist.');

            return self::INVALID;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateInput, $company->timezone ?: config('app.timezone', 'UTC'));
        if (! $date || $date->format('Y-m-d') !== $dateInput) {
            $this->error('The specified attendance date is invalid.');

            return self::INVALID;
        }

        if ($date->greaterThan(CarbonImmutable::now($company->timezone ?: config('app.timezone', 'UTC'))->startOfDay())) {
            $this->error('Future attendance dates cannot be materialized.');

            return self::INVALID;
        }

        $counts = ['materialized' => 0, 'skipped' => 0, 'failed' => 0];

        Employee::query()->where('company_id', $company->id)
            ->where('employment_status', EmploymentStatus::Active->value)
            ->orderBy('id')->chunkById(100, function ($employees) use ($materializer, $date, &$counts): void {
                foreach ($employees as $employee) {
                    try {
                        $existing = DailyAttendanceRecord::query()
                            ->where('company_id', $employee->company_id)
                            ->where('employee_id', $employee->id)
                            ->whereDate('attendance_date', $date->toDateString())
                            ->first();
                        $historyCount = $existing?->histories()->count() ?? 0;
                        $materializer->materialize($employee, $date);
                        $updatedHistoryCount = $existing
                            ? $existing->histories()->count()
                            : 1;

                        if ($existing && $updatedHistoryCount === $historyCount) {
                            $counts['skipped']++;
                        } else {
                            $counts['materialized']++;
                        }
                    } catch (Throwable $exception) {
                        $counts['failed']++;
                        $this->warn("Employee {$employee->id} failed: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Materialized: {$counts['materialized']}; skipped unchanged: {$counts['skipped']}; failed: {$counts['failed']}.");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
