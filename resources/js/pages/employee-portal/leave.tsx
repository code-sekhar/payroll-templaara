import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Link } from '@inertiajs/react';

interface LeaveBalance {
    leaveType: string;
    periodStart: string | null;
    periodEnd: string | null;
    configured: boolean;
    available: string | null;
    used: string | null;
    pending: string | null;
}

interface RecentLeaveRequest {
    leaveType: string;
    startDate: string;
    endDate: string;
    requestedDays: string;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    href: string;
}

interface EmployeeLeavePageProps {
    employeeLeave: {
        asOfDate: string;
        hasApplicableLeaveTypes: boolean;
        balances: LeaveBalance[];
        recentRequests: RecentLeaveRequest[];
    };
    canAccessManagement: boolean;
    canApplyForLeave: boolean;
    companyName: string;
    employeeName: string;
}

function formatAmount(value: string): string {
    return value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
}

function amountWithUnit(value: string): string {
    const amount = formatAmount(value);

    return `${amount} ${amount === '1' || amount === '-1' ? 'day' : 'days'}`;
}

function optionalAmountWithUnit(value: string | null): string {
    return value === null ? '—' : amountWithUnit(value);
}

function formatDateOnly(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(
        new Date(year, month - 1, day),
    );
}

function requestStatusLabel(status: RecentLeaveRequest['status']): string {
    return {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
        cancelled: 'Cancelled',
    }[status];
}

function periodLabel(start: string | null, end: string | null): string | null {
    if (!start || !end) {
        return null;
    }

    return `${formatDateOnly(start)} – ${formatDateOnly(end)}`;
}

export default function EmployeeLeavePage({
    employeeLeave,
    canAccessManagement,
    canApplyForLeave,
    companyName,
    employeeName,
}: EmployeeLeavePageProps) {
    return (
        <EmployeePortalLayout
            title="My Leave"
            canAccessManagement={canAccessManagement}
            companyName={companyName}
            employeeName={employeeName}
        >
            <header className="grid min-w-0 gap-2">
                <p className="text-sm font-medium text-muted-foreground">Your leave information</p>
                <h1 className="break-words text-3xl font-semibold tracking-tight">My Leave</h1>
                <p className="text-sm text-muted-foreground">Balances are shown as of {formatDateOnly(employeeLeave.asOfDate)}.</p>
                {canApplyForLeave && (
                    <Link
                        href={route('employee.leave.apply')}
                        className="bg-primary text-primary-foreground focus-visible:ring-ring mt-2 inline-flex min-h-11 w-fit items-center justify-center rounded-md px-4 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        Apply for Leave
                    </Link>
                )}
            </header>

            <section aria-labelledby="leave-balances-heading" className="grid min-w-0 gap-4">
                <div className="grid min-w-0 gap-1">
                    <h2 id="leave-balances-heading" className="text-xl font-semibold tracking-tight">Leave balances</h2>
                    <p className="text-sm text-muted-foreground">Each leave type has its own balance period.</p>
                </div>
                {employeeLeave.balances.length > 0 ? (
                    <ul className="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {employeeLeave.balances.map((balance) => (
                            <li key={`${balance.leaveType}-${balance.periodStart ?? 'unavailable'}`} className="min-w-0">
                                <Card className="h-full min-w-0">
                                    <CardHeader className="min-w-0 gap-2 p-4 sm:p-5">
                                        <h3 className="break-words text-lg font-semibold">{balance.leaveType}</h3>
                                        <CardDescription className="break-words">
                                            {periodLabel(balance.periodStart, balance.periodEnd) ?? 'Current balance period'}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid min-w-0 gap-3 p-4 pt-0 sm:p-5 sm:pt-0">
                                        {balance.configured ? (
                                            <>
                                                <div className="grid gap-1">
                                                    <p className="text-sm text-muted-foreground">Available</p>
                                                    <p className="break-words text-2xl font-semibold tabular-nums">
                                                        {optionalAmountWithUnit(balance.available)}
                                                    </p>
                                                </div>
                                                <dl className="grid grid-cols-2 gap-3 border-t pt-3 text-sm">
                                                    <div className="grid min-w-0 gap-1">
                                                        <dt className="text-muted-foreground">Used</dt>
                                                        <dd className="break-words font-medium tabular-nums">
                                                            {optionalAmountWithUnit(balance.used)}
                                                        </dd>
                                                    </div>
                                                    <div className="grid min-w-0 gap-1">
                                                        <dt className="text-muted-foreground">Pending</dt>
                                                        <dd className="break-words font-medium tabular-nums">
                                                            {optionalAmountWithUnit(balance.pending)}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Leave balance is not available yet. Please contact HR.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <Card>
                        <CardContent className="p-4 text-sm text-muted-foreground sm:p-5">
                            {employeeLeave.hasApplicableLeaveTypes
                                ? 'Leave balance is not available yet. Please contact HR.'
                                : 'No leave policy is currently assigned to you.'}
                        </CardContent>
                    </Card>
                )}
            </section>

            <section aria-labelledby="recent-leave-heading" className="grid min-w-0 gap-4">
                <div className="grid min-w-0 gap-1">
                    <div className="flex min-w-0 flex-wrap items-center justify-between gap-2">
                        <h2 id="recent-leave-heading" className="text-xl font-semibold tracking-tight">Recent requests</h2>
                        <Link
                            href={route('employee.leave.requests.index')}
                            className="text-primary focus-visible:ring-ring min-h-11 inline-flex items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            View all requests
                        </Link>
                    </div>
                    <p className="text-sm text-muted-foreground">Your five most recently submitted leave requests.</p>
                </div>
                <Card className="min-w-0">
                    <CardContent className="p-4 sm:p-5">
                        {employeeLeave.recentRequests.length > 0 ? (
                            <ul className="grid min-w-0 divide-y">
                                {employeeLeave.recentRequests.map((request, index) => (
                                    <li key={`${request.startDate}-${request.status}-${index}`} className="grid min-w-0 gap-2 py-4 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                        <div className="grid min-w-0 gap-1">
                                            <Link href={request.href} className="text-primary focus-visible:ring-ring w-fit max-w-full break-words font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden">
                                                {request.leaveType}
                                            </Link>
                                            <p className="break-words text-sm text-muted-foreground">
                                                {formatDateOnly(request.startDate)}
                                                {request.startDate !== request.endDate && ` – ${formatDateOnly(request.endDate)}`}
                                                {' · '}{amountWithUnit(request.requestedDays)}
                                            </p>
                                        </div>
                                        <Badge variant="outline" className="w-fit max-w-full whitespace-normal break-words">
                                            {requestStatusLabel(request.status)}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">No leave requests yet.</p>
                        )}
                    </CardContent>
                </Card>
            </section>
        </EmployeePortalLayout>
    );
}
