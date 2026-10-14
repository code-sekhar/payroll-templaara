<?php

namespace App\Services\Employees;

final readonly class EmployeeLeaveRequestHistoryData
{
    /**
     * @param  list<array{leaveType: string, startDate: string, endDate: string, requestedDays: string, status: string, statusLabel: string, requestedAt: string, href: string}>  $requests
     * @param  list<array{id: int, name: string}>  $leaveTypes
     * @param  array{status: string|null, leave_type_id: string|null, date: string|null}  $filters
     * @param  array{currentPage: int, lastPage: int, total: int, from: int|null, to: int|null, previousUrl: string|null, nextUrl: string|null}  $pagination
     */
    public function __construct(
        public array $requests,
        public array $leaveTypes,
        public array $filters,
        public array $pagination,
    ) {}
}
