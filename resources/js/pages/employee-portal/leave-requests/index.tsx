import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

interface LeaveRequestRow {
    leaveType: string;
    startDate: string;
    endDate: string;
    requestedDays: string;
    status: LeaveStatus;
    statusLabel: string;
    requestedAt: string;
    href: string;
}

interface LeaveTypeFilter {
    id: number;
    name: string;
}

interface LeaveRequestHistory {
    requests: LeaveRequestRow[];
    leaveTypes: LeaveTypeFilter[];
    filters: {
        status: string | null;
        leave_type_id: string | null;
        date: string | null;
    };
    pagination: {
        currentPage: number;
        lastPage: number;
        total: number;
        from: number | null;
        to: number | null;
        previousUrl: string | null;
        nextUrl: string | null;
    };
}

interface EmployeeLeaveRequestHistoryPageProps {
    history: LeaveRequestHistory;
    canAccessManagement: boolean;
    canApplyForLeave: boolean;
    companyName: string;
    employeeName: string;
}

interface LeaveHistoryFilters {
    [key: string]: string;
    status: string;
    leave_type_id: string;
    date: string;
}

function formatDateOnly(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(
        new Date(year, month - 1, day),
    );
}

function amountWithUnit(value: string): string {
    const amount = value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');

    return `${amount} ${amount === '1' || amount === '-1' ? 'day' : 'days'}`;
}

export default function EmployeeLeaveRequestHistoryPage({
    history,
    canAccessManagement,
    canApplyForLeave,
    companyName,
    employeeName,
}: EmployeeLeaveRequestHistoryPageProps) {
    const filters = useForm<LeaveHistoryFilters>({
        status: history.filters.status ?? '',
        leave_type_id: history.filters.leave_type_id ?? '',
        date: history.filters.date ?? '',
    });
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        filters.get(route('employee.leave.requests.index'), { preserveScroll: true, replace: true });
    };

    return (
        <EmployeePortalLayout
            title="My Leave Requests"
            canAccessManagement={canAccessManagement}
            companyName={companyName}
            employeeName={employeeName}
        >
            <Head title="My Leave Requests" />
            <header className="grid min-w-0 gap-2">
                <Link
                    href={route('employee.leave.index')}
                    className="text-primary focus-visible:ring-ring inline-flex min-h-11 w-fit items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Back to My Leave
                </Link>
                <h1 className="break-words text-3xl font-semibold tracking-tight">My Leave Requests</h1>
                <p className="text-sm text-muted-foreground">Browse the requests you have submitted and open one for its saved details.</p>
            </header>

            <Card className="min-w-0">
                <CardContent className="p-4 sm:p-5">
                    <form onSubmit={submit} className="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                        <div className="grid min-w-0 gap-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                value={filters.data.status}
                                onChange={(event) => filters.setData('status', event.currentTarget.value)}
                                className="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                <option value="">All statuses</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div className="grid min-w-0 gap-2">
                            <Label htmlFor="leave_type_id">Leave type</Label>
                            <select
                                id="leave_type_id"
                                value={filters.data.leave_type_id}
                                onChange={(event) => filters.setData('leave_type_id', event.currentTarget.value)}
                                className="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                <option value="">All leave types</option>
                                {history.leaveTypes.map((leaveType) => (
                                    <option key={leaveType.id} value={leaveType.id}>
                                        {leaveType.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid min-w-0 gap-2">
                            <Label htmlFor="date">Includes date</Label>
                            <Input
                                id="date"
                                type="date"
                                value={filters.data.date}
                                onChange={(event) => filters.setData('date', event.currentTarget.value)}
                                className="min-h-11 min-w-0"
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="submit" disabled={filters.processing} className="min-h-11">Apply filters</Button>
                            <Button variant="outline" asChild className="min-h-11">
                                <Link href={route('employee.leave.requests.index')}>Clear</Link>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <section aria-labelledby="requests-heading" className="grid min-w-0 gap-4">
                <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-2">
                    <h2 id="requests-heading" className="text-xl font-semibold tracking-tight">Request history</h2>
                    <p className="text-sm text-muted-foreground">
                        {history.pagination.total === 0
                            ? 'No matching requests'
                            : `Showing ${history.pagination.from}–${history.pagination.to} of ${history.pagination.total}`}
                    </p>
                </div>

                {history.requests.length > 0 ? (
                    <ul className="grid min-w-0 gap-3">
                        {history.requests.map((leaveRequest) => (
                            <li key={leaveRequest.href} className="min-w-0">
                                <Card className="min-w-0">
                                    <CardContent className="grid min-w-0 gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-5">
                                        <div className="grid min-w-0 gap-2">
                                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                                <h3 className="min-w-0 break-words text-base font-semibold">{leaveRequest.leaveType}</h3>
                                                <Badge variant="outline" className="max-w-full whitespace-normal break-words">{leaveRequest.statusLabel}</Badge>
                                            </div>
                                            <p className="break-words text-sm text-muted-foreground">
                                                {formatDateOnly(leaveRequest.startDate)}
                                                {leaveRequest.startDate !== leaveRequest.endDate && ` – ${formatDateOnly(leaveRequest.endDate)}`}
                                                {' · '}{amountWithUnit(leaveRequest.requestedDays)}
                                            </p>
                                            <p className="break-words text-xs text-muted-foreground">Submitted {leaveRequest.requestedAt}</p>
                                        </div>
                                        <Button variant="outline" asChild className="min-h-11 w-full sm:w-auto">
                                            <Link href={leaveRequest.href}>View {leaveRequest.leaveType} request</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <Card>
                        <CardContent className="grid gap-3 p-4 sm:p-5">
                            <p className="text-sm text-muted-foreground">
                                {history.pagination.total === 0 && !history.filters.status && !history.filters.leave_type_id && !history.filters.date
                                    ? 'You have not submitted any leave requests yet.'
                                    : 'No leave requests match these filters.'}
                            </p>
                            {canApplyForLeave && history.pagination.total === 0 && (
                                <Link href={route('employee.leave.apply')} className="text-primary focus-visible:ring-ring min-h-11 inline-flex w-fit items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden">
                                    Apply for Leave
                                </Link>
                            )}
                        </CardContent>
                    </Card>
                )}

                {history.pagination.lastPage > 1 && (
                    <nav aria-label="Leave request pages" className="flex flex-wrap items-center justify-between gap-3">
                        {history.pagination.previousUrl ? (
                            <Button variant="outline" asChild className="min-h-11">
                                <Link href={history.pagination.previousUrl}>Previous</Link>
                            </Button>
                        ) : <span />}
                        <p aria-live="polite" className="text-sm text-muted-foreground">Page {history.pagination.currentPage} of {history.pagination.lastPage}</p>
                        {history.pagination.nextUrl ? (
                            <Button variant="outline" asChild className="min-h-11">
                                <Link href={history.pagination.nextUrl}>Next</Link>
                            </Button>
                        ) : <span />}
                    </nav>
                )}
            </section>
            {canApplyForLeave && history.requests.length > 0 && (
                <Link href={route('employee.leave.apply')} className="text-primary focus-visible:ring-ring min-h-11 inline-flex w-fit items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden">
                    Apply for Leave
                </Link>
            )}
        </EmployeePortalLayout>
    );
}
