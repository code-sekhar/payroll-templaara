<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\LeaveRequest;
use App\Queries\Leave\LeaveReportQuery;
use Carbon\CarbonInterface;

class LeaveReportCsvExporter
{
    public function __construct(private readonly LeaveReportQuery $reportQuery) {}

    /** @param array<string, mixed> $filters
     * @param  resource  $stream
     */
    public function write(Company $company, array $filters, $stream): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $this->writeRow($stream, [
            'Request ID', 'Employee Code', 'Employee Name', 'Department', 'Branch', 'Leave Type',
            'Start Date', 'End Date', 'Requested Days', 'Status', 'Requested At', 'Reviewed By',
            'Reviewed At', 'Cancelled By', 'Cancelled At', 'Cancellation Reason',
        ]);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $requests = $this->reportQuery->filteredQuery($company, $filters)
            ->with([
                'employee:id,company_id,employee_code,first_name,middle_name,last_name,department_id,branch_id',
                'employee.department:id,name',
                'employee.branch:id,name',
                'leaveType:id,name',
                'reviewedBy:id,name',
                'canceller:id,name',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->lazy(500);

        foreach ($requests as $request) {
            /** @var LeaveRequest $request */
            $this->writeRow($stream, [
                $request->getKey(),
                $this->safeCell($request->employee?->employee_code),
                $this->safeCell($request->employee?->full_name),
                $this->safeCell($request->employee?->department?->name),
                $this->safeCell($request->employee?->branch?->name),
                $this->safeCell($request->leaveType?->name),
                $request->start_date->toDateString(),
                $request->end_date->toDateString(),
                (string) $request->requested_days,
                $request->status->value,
                $this->formatTimestamp($request->requested_at, $timezone),
                $this->safeCell($request->reviewedBy?->name),
                $this->formatTimestamp($request->reviewed_at, $timezone),
                $this->safeCell($request->canceller?->name),
                $this->formatTimestamp($request->cancelled_at, $timezone),
                $this->safeCell($request->cancellation_reason),
            ]);
        }
    }

    private function safeCell(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_match('/^[\s\p{Z}\p{Cf}\p{Cc}]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    private function formatTimestamp(?CarbonInterface $timestamp, string $timezone): string
    {
        return $timestamp?->setTimezone($timezone)->toIso8601String() ?? '';
    }

    /** @param resource $stream
     * @param  list<int|string>  $cells
     */
    private function writeRow($stream, array $cells): void
    {
        fputcsv($stream, $cells, ',', '"', '', "\r\n");
    }
}
