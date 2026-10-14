<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveReportIndexRequest;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Queries\Leave\LeaveReportQuery;
use App\Services\Leave\LeaveReportCsvExporter;
use App\Tenancy\ActiveCompany;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveReportController extends Controller
{
    public function index(LeaveReportIndexRequest $request, Company $company, LeaveReportQuery $report): Response
    {
        abort_unless(app(ActiveCompany::class)->current()?->getKey() === $company->getKey(), 404);
        $filters = $request->validated();
        $requests = $report->paginate($company, $filters)->through(fn (LeaveRequest $item): array => [
            'id' => $item->getKey(),
            'employee' => [
                'id' => $item->employee?->getKey(),
                'name' => $item->employee?->full_name ?? 'Archived employee',
                'code' => $item->employee?->employee_code ?? '—',
            ],
            'leave_type' => ['id' => $item->leaveType?->getKey(), 'name' => $item->leaveType?->name ?? 'Archived leave type'],
            'start_date' => $item->start_date->toDateString(),
            'end_date' => $item->end_date->toDateString(),
            'requested_days' => (string) $item->requested_days,
            'status' => $item->status->value,
            'requested_at' => $item->requested_at?->toDateTimeString(),
            'reviewer' => $item->reviewedBy?->name,
            'reviewed_at' => $item->reviewed_at?->toDateTimeString(),
            'canceller' => $item->canceller?->name,
            'cancelled_at' => $item->cancelled_at?->toDateTimeString(),
            'cancellation_reason' => $item->cancellation_reason,
            'url' => route('companies.leave-requests.show', [$company, $item]),
        ]);
        $options = $report->options($company);

        return Inertia::render('leave-reports/index', [
            'company' => $company->only('id', 'name'),
            'exportUrl' => route('companies.leave-reports.export', $company),
            'requests' => $requests,
            'summary' => $report->summary($company, $filters),
            'filters' => array_merge([
                'search' => '', 'status' => '', 'leave_type_id' => '', 'employee_id' => '',
                'department_id' => '', 'branch_id' => '', 'date_from' => '', 'date_to' => '',
            ], $filters),
            'options' => [
                'leaveTypes' => $options['leaveTypes'],
                'employees' => $options['employees']->map(fn ($employee): array => [
                    'id' => $employee->id,
                    'name' => $employee->full_name,
                    'code' => $employee->employee_code,
                ])->values(),
                'departments' => $options['departments'],
                'branches' => $options['branches'],
                'statuses' => $options['statuses'],
            ],
        ]);
    }

    public function export(LeaveReportIndexRequest $request, Company $company, LeaveReportCsvExporter $exporter): StreamedResponse
    {
        abort_unless(app(ActiveCompany::class)->current()?->getKey() === $company->getKey(), 404);
        $filters = $request->validated();
        unset($filters['page']);

        $timezone = $company->timezone ?: config('app.timezone', 'UTC');
        $filename = filled($filters['date_from'] ?? null) && filled($filters['date_to'] ?? null)
            ? "leave-report-{$filters['date_from']}-to-{$filters['date_to']}.csv"
            : 'leave-report-'.CarbonImmutable::now($timezone)->toDateString().'.csv';

        return response()->streamDownload(function () use ($company, $filters, $exporter): void {
            $stream = fopen('php://output', 'wb');

            try {
                $exporter->write($company, $filters, $stream);
            } finally {
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
