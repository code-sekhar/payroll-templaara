import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import EmployeePunchCard, { type EmployeePunchState } from '@/components/employee-punch-card';

interface EmployeePortalPageProps {
    employeePortal: {
        companyName: string;
        canAccessManagement: boolean;
        punchState: EmployeePunchState;
        employee: {
            name: string;
            employeeCode: string | null;
            designation: string | null;
            department: string | null;
        };
        today: {
            date: string;
            dateLabel: string;
            greeting: string;
            workStatus: string;
            holidayName: string | null;
            approvedLeaveType: string | null;
            shift: { name: string; start: string; end: string } | null;
            attendance: { status: string; checkIn: string | null; checkOut: string | null } | null;
        };
        leaveBalances: { leaveType: string; balance: string }[];
        pendingLeaveRequestCount: number;
        recentLeaveRequests: {
            leaveType: string;
            startDate: string;
            endDate: string;
            requestedDays: string;
            status: string;
            requestedAt: string | null;
        }[];
        recentAttendance: { date: string; status: string; checkIn: string | null; checkOut: string | null }[];
    };
}

function formatCalendarDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(year, month - 1, day));
}

function formatShiftTime(value: string): string {
    const [hourValue, minuteValue] = value.split(':').map(Number);
    const period = hourValue >= 12 ? 'PM' : 'AM';
    const hour = hourValue % 12 || 12;

    return `${hour}:${String(minuteValue).padStart(2, '0')} ${period}`;
}

function formatLeaveAmount(value: string): string {
    return value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
}

function formatStatus(value: string): string {
    const label = value.replaceAll('_', ' ');

    return label.charAt(0).toLocaleUpperCase() + label.slice(1);
}

export default function EmployeePortalHome({ employeePortal }: EmployeePortalPageProps) {
    const { employee, today } = employeePortal;

    return (
        <EmployeePortalLayout
            title="Employee Home"
            canAccessManagement={employeePortal.canAccessManagement}
            companyName={employeePortal.companyName}
            employeeName={employee.name}
        >
            <header className="flex flex-col gap-2">
                <h1 className="break-words text-2xl font-semibold tracking-tight sm:text-3xl">{today.greeting}, {employee.name}</h1>
                <p className="break-words text-muted-foreground">{today.dateLabel}</p>
                {(employee.employeeCode || employee.designation || employee.department) && (
                    <p className="break-words text-sm text-muted-foreground">
                        {[employee.employeeCode, employee.designation, employee.department].filter(Boolean).join(' · ')}
                    </p>
                )}
            </header>

            <section aria-label="Punch attendance" className="min-w-0">
                <EmployeePunchCard initialState={employeePortal.punchState} />
            </section>

            <section aria-labelledby="today-heading" className="grid min-w-0 gap-4 md:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <CardDescription>{today.dateLabel}</CardDescription>
                        <h2 id="today-heading" className="flex min-w-0 flex-wrap items-center justify-between gap-3 text-xl font-semibold leading-none tracking-tight">
                            <span>Today&apos;s Work</span>
                            <Badge variant="outline" className="max-w-full whitespace-normal break-words text-left">{today.workStatus}</Badge>
                        </h2>
                    </CardHeader>
                    <CardContent className="flex min-w-0 flex-col gap-2 p-4 pt-0 text-sm sm:p-6 sm:pt-0">
                        {today.holidayName && <p className="break-words">{today.holidayName}</p>}
                        {today.approvedLeaveType && <p className="break-words">Approved leave · {today.approvedLeaveType}</p>}
                        {today.shift ? (
                            <>
                                <p className="break-words font-medium">{today.shift.name}</p>
                                <p className="text-muted-foreground">{formatShiftTime(today.shift.start)} – {formatShiftTime(today.shift.end)}</p>
                            </>
                        ) : (
                            <p className="text-muted-foreground">No shift scheduled for today.</p>
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <CardDescription>Today&apos;s Attendance</CardDescription>
                        <h2 className="text-xl font-semibold leading-none tracking-tight">
                            {today.attendance ? <Badge variant="outline">{today.attendance.status}</Badge> : 'Not recorded'}
                        </h2>
                    </CardHeader>
                    <CardContent className="grid min-w-0 grid-cols-2 gap-4 p-4 pt-0 text-sm sm:p-6 sm:pt-0">
                        <div>
                            <p className="text-muted-foreground">Check in</p>
                            <p className="font-medium">{today.attendance?.checkIn ?? '—'}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">Check out</p>
                            <p className="font-medium">{today.attendance?.checkOut ?? '—'}</p>
                        </div>
                        {!today.attendance && <p className="col-span-2 text-muted-foreground">No attendance has been recorded for today.</p>}
                    </CardContent>
                </Card>
            </section>

            <section aria-labelledby="leave-balances-heading" className="grid min-w-0 gap-4 md:grid-cols-[minmax(0,1.4fr)_minmax(16rem,1fr)]">
                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <h2 id="leave-balances-heading" className="text-2xl font-semibold leading-none tracking-tight">Leave balances</h2>
                        <CardDescription>Current balance by leave type</CardDescription>
                    </CardHeader>
                    <CardContent className="min-w-0 p-4 pt-0 sm:p-6 sm:pt-0">
                        {employeePortal.leaveBalances.length > 0 ? (
                            <ul className="divide-y">
                                {employeePortal.leaveBalances.map((balance) => (
                                    <li key={balance.leaveType} className="flex min-w-0 flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                                        <span className="min-w-0 break-words font-medium">{balance.leaveType}</span>
                                        <span className="shrink-0 tabular-nums">{formatLeaveAmount(balance.balance)} {formatLeaveAmount(balance.balance) === '1' ? 'day' : 'days'}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">No leave balance is available yet.</p>
                        )}
                    </CardContent>
                </Card>
                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <h2 className="text-2xl font-semibold leading-none tracking-tight">Pending leave requests</h2>
                        <CardDescription>Your requests awaiting review</CardDescription>
                    </CardHeader>
                    <CardContent className="p-4 pt-0 sm:p-6 sm:pt-0">
                        <p className="text-3xl font-semibold tabular-nums" aria-label={`${employeePortal.pendingLeaveRequestCount} pending leave requests`}>{employeePortal.pendingLeaveRequestCount}</p>
                    </CardContent>
                </Card>
            </section>

            <section aria-labelledby="recent-leave-heading" className="grid min-w-0 gap-4 md:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <h2 id="recent-leave-heading" className="text-2xl font-semibold leading-none tracking-tight">Recent leave requests</h2>
                    </CardHeader>
                    <CardContent className="min-w-0 p-4 pt-0 sm:p-6 sm:pt-0">
                        {employeePortal.recentLeaveRequests.length > 0 ? (
                            <ul className="flex flex-col gap-4">
                                {employeePortal.recentLeaveRequests.map((request, index) => (
                                    <li key={`${request.startDate}-${request.status}-${index}`} className="flex min-w-0 flex-col gap-1 border-b pb-4 last:border-0 last:pb-0">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="min-w-0 break-words font-medium">{request.leaveType}</p>
                                            <Badge variant="outline" className="max-w-full whitespace-normal break-words">{formatStatus(request.status)}</Badge>
                                        </div>
                                        <p className="break-words text-sm text-muted-foreground">
                                            {formatCalendarDate(request.startDate)}{request.startDate !== request.endDate && ` – ${formatCalendarDate(request.endDate)}`}
                                            {' · '}{formatLeaveAmount(request.requestedDays)} {formatLeaveAmount(request.requestedDays) === '1' ? 'day' : 'days'}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">No leave requests yet.</p>
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <h2 className="text-2xl font-semibold leading-none tracking-tight">Recent attendance</h2>
                    </CardHeader>
                    <CardContent className="min-w-0 p-4 pt-0 sm:p-6 sm:pt-0">
                        {employeePortal.recentAttendance.length > 0 ? (
                            <ul className="flex flex-col gap-4">
                                {employeePortal.recentAttendance.map((record) => (
                                    <li key={record.date} className="flex min-w-0 flex-col gap-1 border-b pb-4 last:border-0 last:pb-0">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="font-medium">{formatCalendarDate(record.date)}</p>
                                            <Badge variant="outline" className="max-w-full whitespace-normal break-words">{record.status}</Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Check in {record.checkIn ?? '—'} <span aria-hidden="true">·</span> Check out {record.checkOut ?? '—'}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">No attendance has been recorded yet.</p>
                        )}
                    </CardContent>
                </Card>
            </section>
        </EmployeePortalLayout>
    );
}
