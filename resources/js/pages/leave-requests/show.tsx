import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Day = { date: string; type: string; amount: string; schedule: string | null; holiday: string | null };
type LeaveRequest = {
    id: number;
    employee: { name: string; code: string };
    leaveType: { name: string; code: string };
    leavePolicy: { name: string; code: string };
    assignmentId: number;
    balanceAccountId: number | null;
    startDate: string;
    endDate: string;
    requestedDays: string;
    duration: string;
    status: string;
    reason: string | null;
    employeeNote: string | null;
    requestedBy: string | null;
    requestedAt: string;
    cancelledBy: string | null;
    cancelledAt: string | null;
    cancellationReason: string | null;
    reviewedBy: string | null;
    reviewedAt: string | null;
    reviewNote: string | null;
    days: Day[];
};

export default function LeaveRequestShow({
    leaveRequest,
    balance,
    canCancel,
    canCancelApproved = false,
    canApprove,
    canReject,
    cancelUrl,
    cancelApprovedUrl = null,
    approveUrl,
    rejectUrl,
    ledgerConsumption,
    backUrl,
}: {
    leaveRequest: LeaveRequest;
    balance: {
        ledger: string;
        otherPendingReserved: string;
        thisRequest: string;
        pending: string;
        projectedProtected: string;
        available: string;
    } | null;
    canCancel: boolean;
    canCancelApproved?: boolean;
    canApprove: boolean;
    canReject: boolean;
    cancelUrl: string | null;
    cancelApprovedUrl?: string | null;
    approveUrl: string | null;
    rejectUrl: string | null;
    ledgerConsumption: { amount: string; effectiveDate: string } | null;
    backUrl: string;
}) {
    const cancellation = useForm({ cancellation_reason: '' });
    const approvedCancellation = useForm({ cancellation_reason: '' });
    const [approvedCancellationOpen, setApprovedCancellationOpen] = useState(false);
    const { flash } = usePage<SharedData>().props;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (cancelUrl && window.confirm('Cancel this pending leave request? Its pending reservation will be released.')) cancellation.post(cancelUrl);
    };
    const approval = useForm({ review_note: '' });
    const rejection = useForm({ review_note: '' });
    const approvalRequestError = (approval.errors as Record<string, string>).request;
    const rejectionRequestError = (rejection.errors as Record<string, string>).request;
    const approvedCancellationRequestError = (approvedCancellation.errors as Record<string, string>).leave_request;
    const approve = (event: FormEvent) => {
        event.preventDefault();
        if (window.confirm(`Approve ${leaveRequest.requestedDays} leave day(s) and deduct the account ledger?`)) approval.post(approveUrl!);
    };
    const reject = (event: FormEvent) => {
        event.preventDefault();
        if (window.confirm('Reject this leave request and release its pending reservation?')) rejection.post(rejectUrl!);
    };
    const cancelApproved = (event: FormEvent) => {
        event.preventDefault();
        if (!cancelApprovedUrl || approvedCancellation.processing) return;
        approvedCancellation.post(cancelApprovedUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setApprovedCancellationOpen(false);
                approvedCancellation.reset();
            },
        });
    };
    const format = (value: string) => value.replaceAll('_', ' ');
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Leave requests', href: backUrl },
                { title: `Request #${leaveRequest.id}`, href: '#' },
            ]}
        >
            <Head title={`Leave request #${leaveRequest.id}`} />
            <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Leave request #{leaveRequest.id}</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Submitted {leaveRequest.requestedAt} by {leaveRequest.requestedBy ?? '—'}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={backUrl}>Back to requests</Link>
                    </Button>
                </header>
                {flash?.success && (
                    <p
                        role="status"
                        className="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100"
                    >
                        {flash.success}
                    </p>
                )}
                {flash?.warning && (
                    <p
                        role="alert"
                        className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                    >
                        {flash.warning}
                    </p>
                )}
                <InputError id="approved-cancellation-request-error" role="alert" message={approvedCancellationRequestError} />
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p className="text-muted-foreground text-xs">Employee</p>
                        <p className="font-medium">{leaveRequest.employee.name}</p>
                        <p className="text-muted-foreground text-xs">{leaveRequest.employee.code}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">Leave type</p>
                        <p>
                            {leaveRequest.leaveType.name} ({leaveRequest.leaveType.code})
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">Policy / assignment</p>
                        <p>
                            {leaveRequest.leavePolicy.name} · #{leaveRequest.assignmentId}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">Period</p>
                        <p>
                            {leaveRequest.startDate} – {leaveRequest.endDate}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">Requested amount / status</p>
                        <p>
                            {leaveRequest.requestedDays} days · <span className="capitalize">{leaveRequest.status}</span>
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">Balance account</p>
                        <p>{leaveRequest.balanceAccountId ? `#${leaveRequest.balanceAccountId}` : 'Not applicable'}</p>
                    </div>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <p className="text-muted-foreground text-xs">Reason</p>
                        <p className="whitespace-pre-wrap">{leaveRequest.reason ?? 'No reason provided.'}</p>
                    </div>
                    {leaveRequest.employeeNote && (
                        <div className="sm:col-span-2 lg:col-span-3">
                            <p className="text-muted-foreground text-xs">Additional note</p>
                            <p className="whitespace-pre-wrap">{leaveRequest.employeeNote}</p>
                        </div>
                    )}
                </section>
                {balance && (
                    <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2 lg:grid-cols-5">
                        <div>
                            <p className="text-muted-foreground text-xs">Current ledger balance</p>
                            <p className="text-lg font-semibold">{balance.ledger}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-xs">Other pending reserved</p>
                            <p className="text-lg font-semibold">{balance.otherPendingReserved}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-xs">This request</p>
                            <p className="text-lg font-semibold">{balance.thisRequest}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-xs">Projected protected balance</p>
                            <p className="text-lg font-semibold">{balance.projectedProtected}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-xs">Available for new requests</p>
                            <p className="text-lg font-semibold">{balance.available}</p>
                        </div>
                    </section>
                )}
                <section className="overflow-hidden rounded-xl border">
                    <h2 className="px-4 py-3 font-semibold">Resolved day breakdown</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    {['Date', 'Day type', 'Amount', 'Schedule', 'Holiday'].map((label) => (
                                        <th key={label} className="px-4 py-3 font-medium">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {leaveRequest.days.map((day) => (
                                    <tr key={day.date} className="border-t">
                                        <td className="px-4 py-3">{day.date}</td>
                                        <td className="px-4 py-3 capitalize">{format(day.type)}</td>
                                        <td className="px-4 py-3">{day.amount}</td>
                                        <td className="px-4 py-3">{day.schedule ?? '—'}</td>
                                        <td className="px-4 py-3">{day.holiday ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
                {leaveRequest.cancelledAt && (
                    <section className="rounded-xl border p-5">
                        <h2 className="font-semibold">Cancellation audit</h2>
                        <p className="mt-2 text-sm">
                            Cancelled {leaveRequest.cancelledAt} by {leaveRequest.cancelledBy ?? '—'}
                        </p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">{leaveRequest.cancellationReason}</p>
                    </section>
                )}
                {leaveRequest.reviewedAt && (
                    <section className="rounded-xl border p-5">
                        <h2 className="font-semibold">
                            {leaveRequest.status === 'approved' || (leaveRequest.status === 'cancelled' && ledgerConsumption) ? 'Approval' : 'Review'}{' '}
                            audit
                        </h2>
                        <p className="mt-2 text-sm">
                            {leaveRequest.status === 'approved' || (leaveRequest.status === 'cancelled' && ledgerConsumption)
                                ? 'Approved'
                                : 'Rejected'}{' '}
                            {leaveRequest.reviewedAt} by {leaveRequest.reviewedBy ?? '—'}
                        </p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">{leaveRequest.reviewNote ?? 'No review note.'}</p>
                        {leaveRequest.status === 'rejected' && <p className="mt-2 text-sm">Ledger impact: none</p>}
                        {ledgerConsumption && (
                            <p className="mt-2 text-sm">
                                Ledger consumption: {ledgerConsumption.amount} effective {ledgerConsumption.effectiveDate}
                            </p>
                        )}
                    </section>
                )}
                {(canApprove || canReject) && (
                    <section className="grid gap-4 rounded-xl border p-5 md:grid-cols-2">
                        <form onSubmit={approve} className="grid content-start gap-3">
                            <h2 className="font-semibold">Approve leave</h2>
                            <label className="grid gap-2 text-sm">
                                Review note (optional)
                                <textarea
                                    maxLength={5000}
                                    rows={3}
                                    value={approval.data.review_note}
                                    onChange={(event) => approval.setData('review_note', event.target.value)}
                                    className="bg-background rounded-md border px-3 py-2"
                                />
                            </label>
                            <InputError message={approval.errors.review_note ?? approvalRequestError} />
                            {canApprove && <Button disabled={approval.processing || !approveUrl}>Approve and consume balance</Button>}
                        </form>
                        <form onSubmit={reject} className="grid content-start gap-3">
                            <h2 className="font-semibold">Reject leave</h2>
                            <label className="grid gap-2 text-sm">
                                Review reason
                                <textarea
                                    required
                                    minLength={5}
                                    maxLength={5000}
                                    rows={3}
                                    value={rejection.data.review_note}
                                    onChange={(event) => rejection.setData('review_note', event.target.value)}
                                    className="bg-background rounded-md border px-3 py-2"
                                />
                            </label>
                            <InputError message={rejection.errors.review_note ?? rejectionRequestError} />
                            {canReject && (
                                <Button variant="destructive" disabled={rejection.processing || !rejectUrl}>
                                    Reject and release reservation
                                </Button>
                            )}
                        </form>
                    </section>
                )}
                {canCancel && cancelUrl && (
                    <form onSubmit={submit} className="grid gap-3 rounded-xl border p-5">
                        <h2 className="font-semibold">Cancel pending request</h2>
                        <label className="grid gap-2 text-sm">
                            Cancellation reason
                            <textarea
                                required
                                minLength={3}
                                maxLength={2000}
                                rows={2}
                                value={cancellation.data.cancellation_reason}
                                onChange={(event) => cancellation.setData('cancellation_reason', event.target.value)}
                                className="bg-background rounded-md border px-3 py-2"
                            />
                        </label>
                        <InputError message={cancellation.errors.cancellation_reason} />
                        <Button variant="destructive" disabled={cancellation.processing}>
                            Cancel request
                        </Button>
                    </form>
                )}
                {canCancelApproved && cancelApprovedUrl && (
                    <section className="border-destructive/30 rounded-xl border p-5">
                        <h2 className="font-semibold">Approved leave cancellation</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Cancelling this approved leave restores the used leave balance and recalculates affected attendance dates.
                        </p>
                        <Dialog
                            open={approvedCancellationOpen}
                            onOpenChange={(open) => {
                                if (!approvedCancellation.processing) setApprovedCancellationOpen(open);
                            }}
                        >
                            <DialogTrigger asChild>
                                <Button className="mt-4" variant="destructive">
                                    Cancel Approved Leave
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[90vh] overflow-y-auto">
                                <DialogTitle>Cancel approved leave?</DialogTitle>
                                <DialogDescription>
                                    Review the affected request before confirming. This restores the used leave balance and recalculates the affected
                                    attendance dates.
                                </DialogDescription>
                                <dl className="grid gap-2 rounded-md border p-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">Employee</dt>
                                        <dd className="font-medium">
                                            {leaveRequest.employee.name} ({leaveRequest.employee.code})
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Leave type</dt>
                                        <dd className="font-medium">{leaveRequest.leaveType.name}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Dates</dt>
                                        <dd>
                                            {leaveRequest.startDate} – {leaveRequest.endDate}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Chargeable amount</dt>
                                        <dd>{leaveRequest.requestedDays} days</dd>
                                    </div>
                                </dl>
                                <form onSubmit={cancelApproved} className="grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="approved-cancellation-reason">Cancellation reason</Label>
                                        <textarea
                                            id="approved-cancellation-reason"
                                            name="cancellation_reason"
                                            required
                                            minLength={3}
                                            maxLength={1000}
                                            aria-invalid={Boolean(approvedCancellation.errors.cancellation_reason)}
                                            aria-describedby="approved-cancellation-reason-error"
                                            rows={4}
                                            value={approvedCancellation.data.cancellation_reason}
                                            onChange={(event) => approvedCancellation.setData('cancellation_reason', event.target.value)}
                                            className="bg-background rounded-md border px-3 py-2"
                                        />
                                        <InputError
                                            id="approved-cancellation-reason-error"
                                            message={approvedCancellation.errors.cancellation_reason}
                                        />
                                    </div>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button type="button" variant="outline" disabled={approvedCancellation.processing}>
                                                Keep Leave
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={approvedCancellation.processing || !approvedCancellation.data.cancellation_reason.trim()}
                                        >
                                            {approvedCancellation.processing && (
                                                <LoaderCircle className="mr-2 size-4 animate-spin" aria-hidden="true" />
                                            )}
                                            {approvedCancellation.processing ? 'Cancelling…' : 'Cancel Approved Leave'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}
