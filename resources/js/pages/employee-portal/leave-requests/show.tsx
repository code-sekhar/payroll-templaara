import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

interface LeaveRequestDay {
    date: string;
    dayType: string;
    amount: string;
}

interface LeaveRequestTimelineEvent {
    label: string;
    at: string;
}

interface LeaveRequestDetail {
    leaveType: string;
    status: LeaveStatus;
    statusLabel: string;
    startDate: string;
    endDate: string;
    requestedDays: string;
    duration: string;
    durationLabel: string;
    requestedAt: string;
    reason: string | null;
    timeline: LeaveRequestTimelineEvent[];
    hasDayBreakdown: boolean;
    days: LeaveRequestDay[];
}

interface EmployeeLeaveRequestDetailPageProps {
    leaveRequest: LeaveRequestDetail;
    canWithdraw: boolean;
    withdrawUrl: string | null;
    canAccessManagement: boolean;
    canApplyForLeave: boolean;
    companyName: string;
    employeeName: string;
}

function formatDateOnly(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'long', year: 'numeric' }).format(
        new Date(year, month - 1, day),
    );
}

function amountWithUnit(value: string): string {
    const amount = value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');

    return `${amount} ${amount === '1' || amount === '-1' ? 'day' : 'days'}`;
}

export default function EmployeeLeaveRequestDetailPage({
    leaveRequest,
    canWithdraw,
    withdrawUrl,
    canAccessManagement,
    canApplyForLeave,
    companyName,
    employeeName,
}: EmployeeLeaveRequestDetailPageProps) {
    const [confirmingWithdrawal, setConfirmingWithdrawal] = useState(false);
    const withdrawalButtonRef = useRef<HTMLButtonElement>(null);
    const wasConfirmingWithdrawal = useRef(false);
    const form = useForm({ cancellation_reason: '' });

    useEffect(() => {
        if (wasConfirmingWithdrawal.current && !confirmingWithdrawal) {
            withdrawalButtonRef.current?.focus();
        }

        wasConfirmingWithdrawal.current = confirmingWithdrawal;
    }, [confirmingWithdrawal]);

    function withdrawRequest(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!withdrawUrl) {
            return;
        }

        form.post(withdrawUrl, {
            onSuccess: () => {
                setConfirmingWithdrawal(false);
                form.reset();
            },
        });
    }

    return (
        <EmployeePortalLayout
            title="Leave Request Details"
            canAccessManagement={canAccessManagement}
            companyName={companyName}
            employeeName={employeeName}
        >
            <Head title="Leave Request Details" />
            <header className="grid min-w-0 gap-2">
                <Link
                    href={route('employee.leave.requests.index')}
                    className="text-primary focus-visible:ring-ring inline-flex min-h-11 w-fit items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Back to My Leave Requests
                </Link>
                <div className="flex min-w-0 flex-wrap items-center gap-3">
                    <h1 className="min-w-0 break-words text-3xl font-semibold tracking-tight">{leaveRequest.leaveType}</h1>
                    <Badge variant="outline" className="max-w-full whitespace-normal break-words">{leaveRequest.statusLabel}</Badge>
                </div>
                <p className="break-words text-sm text-muted-foreground">
                    {formatDateOnly(leaveRequest.startDate)}
                    {leaveRequest.startDate !== leaveRequest.endDate && ` – ${formatDateOnly(leaveRequest.endDate)}`}
                    {' · '}{amountWithUnit(leaveRequest.requestedDays)}
                </p>
            </header>

            <section aria-labelledby="request-summary-heading" className="grid min-w-0 gap-4 sm:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader className="p-4 pb-2 sm:p-5 sm:pb-2">
                        <h2 id="request-summary-heading" className="text-lg font-semibold">Request summary</h2>
                    </CardHeader>
                    <CardContent className="grid min-w-0 gap-4 p-4 pt-2 sm:p-5 sm:pt-2">
                        <dl className="grid min-w-0 gap-3 text-sm">
                            <div className="grid min-w-0 gap-1">
                                <dt className="text-muted-foreground">Requested leave</dt>
                                <dd className="break-words font-medium">{amountWithUnit(leaveRequest.requestedDays)} · {leaveRequest.durationLabel}</dd>
                            </div>
                            <div className="grid min-w-0 gap-1">
                                <dt className="text-muted-foreground">Submitted</dt>
                                <dd className="break-words font-medium">{leaveRequest.requestedAt}</dd>
                            </div>
                        </dl>
                        {leaveRequest.reason && (
                            <div className="grid min-w-0 gap-1 border-t pt-3">
                                <h3 className="text-sm text-muted-foreground">Your reason</h3>
                                <p className="break-words whitespace-pre-wrap text-sm">{leaveRequest.reason}</p>
                            </div>
                        )}
                        {leaveRequest.status === 'pending' && (
                            <p role="status" className="border-t pt-3 text-sm text-muted-foreground">Your request is awaiting review.</p>
                        )}
                        {leaveRequest.status === 'approved' && (
                            <p role="status" className="border-t pt-3 text-sm text-muted-foreground">Your leave request has been approved.</p>
                        )}
                        {leaveRequest.status === 'rejected' && (
                            <p role="status" className="border-t pt-3 text-sm text-muted-foreground">Your leave request was not approved.</p>
                        )}
                        {leaveRequest.status === 'cancelled' && (
                            <p role="status" className="border-t pt-3 text-sm text-muted-foreground">This leave request is cancelled.</p>
                        )}
                        {canWithdraw && withdrawUrl && !confirmingWithdrawal && (
                            <div className="border-t pt-3">
                                <button
                                    ref={withdrawalButtonRef}
                                    type="button"
                                    onClick={() => setConfirmingWithdrawal(true)}
                                    className="focus-visible:ring-ring inline-flex min-h-11 items-center rounded-md border border-input px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    Withdraw Request
                                </button>
                            </div>
                        )}
                        {canWithdraw && withdrawUrl && confirmingWithdrawal && (
                            <form onSubmit={withdrawRequest} className="grid min-w-0 gap-3 border-t pt-4" aria-labelledby="withdraw-confirmation-heading">
                                <div className="grid gap-1">
                                    <h3 id="withdraw-confirmation-heading" className="font-medium">Withdraw this pending request?</h3>
                                    <p className="break-words text-sm text-muted-foreground">
                                        This will withdraw your {leaveRequest.leaveType} request for {formatDateOnly(leaveRequest.startDate)}
                                        {leaveRequest.startDate !== leaveRequest.endDate && ` – ${formatDateOnly(leaveRequest.endDate)}`} immediately.
                                        Approved leave cannot be withdrawn here.
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <label htmlFor="cancellation_reason" className="text-sm font-medium">Reason for withdrawal</label>
                                    <textarea
                                        id="cancellation_reason"
                                        name="cancellation_reason"
                                        autoFocus
                                        value={form.data.cancellation_reason}
                                        onChange={(event) => form.setData('cancellation_reason', event.target.value)}
                                        aria-invalid={Boolean(form.errors.cancellation_reason)}
                                        aria-describedby={form.errors.cancellation_reason ? 'cancellation-reason-error' : undefined}
                                        required
                                        minLength={3}
                                        maxLength={2000}
                                        rows={3}
                                        className="border-input placeholder:text-muted-foreground focus-visible:ring-ring min-h-24 w-full rounded-md border bg-background px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-hidden"
                                    />
                                    {form.errors.cancellation_reason && (
                                        <p id="cancellation-reason-error" role="alert" className="text-sm text-destructive">{form.errors.cancellation_reason}</p>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="focus-visible:ring-ring inline-flex min-h-11 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground focus-visible:ring-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50"
                                    >
                                        {form.processing ? 'Withdrawing…' : 'Confirm withdrawal'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={form.processing}
                                        onClick={() => {
                                            setConfirmingWithdrawal(false);
                                            form.clearErrors();
                                        }}
                                        className="focus-visible:ring-ring inline-flex min-h-11 items-center rounded-md border border-input px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-50"
                                    >
                                        Keep request
                                    </button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="p-4 pb-2 sm:p-5 sm:pb-2">
                        <h2 className="text-lg font-semibold">Request timeline</h2>
                    </CardHeader>
                    <CardContent className="p-4 pt-2 sm:p-5 sm:pt-2">
                        {leaveRequest.timeline.length > 0 ? (
                            <ol className="grid gap-4">
                                {leaveRequest.timeline.map((event, index) => (
                                    <li key={`${event.label}-${index}`} className="grid min-w-0 gap-1 border-l-2 pl-3">
                                        <p className="break-words text-sm font-medium">{event.label}</p>
                                        <p className="break-words text-sm text-muted-foreground">{event.at}</p>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <p className="text-sm text-muted-foreground">Request history details are not available.</p>
                        )}
                    </CardContent>
                </Card>
            </section>

            <section aria-labelledby="day-breakdown-heading" className="grid min-w-0 gap-4">
                <div className="grid min-w-0 gap-1">
                    <h2 id="day-breakdown-heading" className="text-xl font-semibold tracking-tight">Day-by-day details</h2>
                    <p className="text-sm text-muted-foreground">These dates reflect the schedule and holiday resolution saved with your request.</p>
                </div>
                <Card className="min-w-0">
                    <CardContent className="p-4 sm:p-5">
                        {leaveRequest.hasDayBreakdown ? (
                            <ul className="grid min-w-0 divide-y">
                                {leaveRequest.days.map((day) => (
                                    <li key={`${day.date}-${day.dayType}`} className="grid min-w-0 gap-1 py-3 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                        <div className="grid min-w-0 gap-1">
                                            <p className="break-words font-medium">{formatDateOnly(day.date)}</p>
                                            <p className="break-words text-sm text-muted-foreground">{day.dayType}</p>
                                        </div>
                                        <p className="break-words text-sm font-medium tabular-nums">{amountWithUnit(day.amount)}</p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">Day details are not available for this request.</p>
                        )}
                    </CardContent>
                </Card>
            </section>

            <footer className="flex flex-wrap items-center gap-3">
                <Link href={route('employee.leave.index')} className="text-primary focus-visible:ring-ring min-h-11 inline-flex items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden">
                    Back to My Leave
                </Link>
                {canApplyForLeave && (
                    <Link href={route('employee.leave.apply')} className="text-primary focus-visible:ring-ring min-h-11 inline-flex items-center rounded-md text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden">
                        Apply for Leave
                    </Link>
                )}
            </footer>
        </EmployeePortalLayout>
    );
}
