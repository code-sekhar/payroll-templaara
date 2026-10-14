<?php

namespace App\Services\Employees;

final readonly class EmployeeLeaveOverviewData
{
    /**
     * @param  array{
     *     asOfDate: string,
     *     hasApplicableLeaveTypes: bool,
     *     balances: list<array{leaveType: string, periodStart: string|null, periodEnd: string|null, configured: bool, available: string|null, used: string|null, pending: string|null}>,
     *     recentRequests: list<array{leaveType: string, startDate: string, endDate: string, requestedDays: string, status: string}>
     * }  $props
     * @param  list<array{id: int, name: string, allowsHalfDay: bool, reasonRequired: bool, configured: bool, available: string|null, pending: string|null}>  $applicationTypes
     */
    public function __construct(public array $props, public array $applicationTypes = []) {}
}
