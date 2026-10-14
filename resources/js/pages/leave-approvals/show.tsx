import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Day = { date: string; dayType: string; amount: string };
type TimelineEvent = { label: string; at: string; actor: string | null };
type Balance = {
    asOfDate: string;
    periodStart: string;
    periodEnd: string;
    ledgerBalance: string;
    pendingReserved: string;
    availableAfterPending: string;
    label: string;
} | null;
type ApprovalRequest = {
    employee: { name: string; code: string | null; department: string | null };
    leaveType: string;
    status: string;
    statusLabel: string;
    startDate: string;
    endDate: string;
    requestedDays: string;
    duration: string;
    submittedAt: string | null;
    reason: string | null;
    employeeNote: string | null;
    reviewNote: string | null;
    cancellationReason: string | null;
    days: Day[];
    timeline: TimelineEvent[];
    balance: Balance;
    decisions: {
        canApprove: boolean;
        approveUrl: string | null;
        canReject: boolean;
        rejectUrl: string | null;
    };
};

function statusVariant(status: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (status === 'approved') return 'default';
    if (status === 'rejected') return 'destructive';
    if (status === 'cancelled') return 'outline';
    return 'secondary';
}

export default function LeaveApprovalDetail({ request, backUrl }: { request: ApprovalRequest; backUrl: string }) {
    const approval = useForm({ review_note: '' });
    const rejection = useForm({ review_note: '' });
    const [approvalOpen, setApprovalOpen] = useState(false);
    const [rejectionOpen, setRejectionOpen] = useState(false);
    const { flash } = usePage<SharedData>().props;
    const approvalRequestError = (approval.errors as Record<string, string>).request;
    const rejectionRequestError = (rejection.errors as Record<string, string>).request;

    function submitApproval(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!request.decisions.approveUrl || approval.processing) return;

        approval.post(request.decisions.approveUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setApprovalOpen(false);
                approval.reset();
            },
        });
    }

    function submitRejection(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!request.decisions.rejectUrl || rejection.processing) return;

        rejection.post(request.decisions.rejectUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setRejectionOpen(false);
                rejection.reset();
            },
        });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Leave Approval Inbox', href: backUrl }, { title: 'Request detail', href: '#' }]}>
            <Head title="Leave request review" />
            <main className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">Leave request</h1>
                        <p className="mt-1 text-sm text-muted-foreground">Submitted {request.submittedAt ?? 'time unavailable'}</p>
                    </div>
                    <Button asChild variant="outline" className="min-h-11"><Link href={backUrl}>Back to inbox</Link></Button>
                </header>

                {flash?.success && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">{flash.success}</p>}
                {approvalRequestError && <p role="alert" className="rounded-lg border border-destructive/50 p-4 text-sm">{approvalRequestError}</p>}
                {rejectionRequestError && <p role="alert" className="rounded-lg border border-destructive/50 p-4 text-sm">{rejectionRequestError}</p>}

                <section aria-labelledby="request-summary-heading" className="grid min-w-0 gap-4 rounded-xl border p-4 sm:grid-cols-2 sm:p-5">
                    <h2 id="request-summary-heading" className="sr-only">Request summary</h2>
                    <Field label="Employee" value={request.employee.name} />
                    <Field label="Employee code" value={request.employee.code ?? 'Not available'} />
                    {request.employee.department && <Field label="Department" value={request.employee.department} />}
                    <Field label="Leave type" value={request.leaveType} />
                    <div className="grid min-w-0 gap-1"><span className="text-sm text-muted-foreground">Status</span><Badge variant={statusVariant(request.status)} className="w-fit">{request.statusLabel}</Badge></div>
                    <Field label="Requested dates" value={`${request.startDate} – ${request.endDate}`} />
                    <Field label="Requested duration" value={`${request.requestedDays} ${request.requestedDays === '1.00' ? 'day' : 'days'} · ${request.duration}`} />
                    <Field label="Submitted" value={request.submittedAt ?? 'Not available'} />
                    <TextField label="Employee reason" value={request.reason ?? 'No reason provided.'} />
                    {request.employeeNote && <TextField label="Additional employee note" value={request.employeeNote} />}
                    {request.reviewNote && <TextField label="Internal reviewer note" value={request.reviewNote} />}
                    {request.cancellationReason && <TextField label="Cancellation context" value={request.cancellationReason} />}
                </section>

                <section className="grid gap-3" aria-labelledby="saved-days-heading">
                    <h2 id="saved-days-heading" className="text-lg font-semibold">Saved day breakdown</h2>
                    <ul className="grid min-w-0 gap-2 sm:grid-cols-2">
                        {request.days.map(day => (
                            <li key={`${day.date}-${day.dayType}`} className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                                <span className="break-words">{day.date} · {day.dayType}</span><span className="shrink-0 font-medium">{day.amount} {day.amount === '1.00' ? 'day' : 'days'}</span>
                            </li>
                        ))}
                    </ul>
                    {request.days.length === 0 && <p className="rounded-lg border p-4 text-sm text-muted-foreground">Saved day details are unavailable.</p>}
                    <p className="text-xs text-muted-foreground">This breakdown uses the dates and day values saved when the request was submitted.</p>
                </section>

                <section className="grid gap-3" aria-labelledby="review-history-heading">
                    <h2 id="review-history-heading" className="text-lg font-semibold">Request history</h2>
                    <ol className="grid gap-2 rounded-xl border p-4">
                        {request.timeline.map((event, index) => <li key={`${event.label}-${index}`} className="flex min-w-0 flex-wrap justify-between gap-2 text-sm"><span>{event.label}{event.actor ? ` · ${event.actor}` : ''}</span><time className="text-muted-foreground">{event.at}</time></li>)}
                    </ol>
                </section>

                <section className="grid gap-3" aria-labelledby="balance-context-heading">
                    <h2 id="balance-context-heading" className="text-lg font-semibold">Balance context</h2>
                    {request.balance ? (
                        <div className="grid min-w-0 gap-4 rounded-xl border p-4 sm:grid-cols-3 sm:p-5">
                            <Field label="Current ledger balance" value={`${request.balance.ledgerBalance} days`} />
                            <Field label="Pending reservations" value={`${request.balance.pendingReserved} days`} />
                            <Field label="Available after pending" value={`${request.balance.availableAfterPending} days`} />
                            <p className="text-sm text-muted-foreground sm:col-span-3">{request.balance.label} As of {request.balance.asOfDate}; account period {request.balance.periodStart} – {request.balance.periodEnd}.</p>
                        </div>
                    ) : (
                        <p className="rounded-xl border p-4 text-sm text-muted-foreground">Current balance context is unavailable for this request or your access does not include balance viewing.</p>
                    )}
                </section>

                {request.status === 'pending' && <p className="rounded-lg bg-muted p-4 text-sm">Pending — awaiting decision.</p>}
                {request.status === 'pending' && !request.decisions.canApprove && !request.decisions.canReject && (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">You can view this request, but your current access does not include decision authority.</p>
                )}

                {request.status === 'pending' && (request.decisions.canApprove || request.decisions.canReject) && (
                    <section aria-labelledby="decision-actions-heading" className="grid min-w-0 gap-3 rounded-xl border p-4 sm:p-5">
                        <div className="grid gap-1">
                            <h2 id="decision-actions-heading" className="text-lg font-semibold">Review this request</h2>
                            <p className="text-sm text-muted-foreground">Choose a decision after checking the saved dates and requested amount.</p>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                            {request.decisions.canApprove && request.decisions.approveUrl && (
                                <Dialog open={approvalOpen} onOpenChange={setApprovalOpen}>
                                    <DialogTrigger asChild>
                                        <Button className="min-h-11 w-full sm:w-auto">Approve request</Button>
                                    </DialogTrigger>
                                    <DialogContent className="w-[calc(100vw-2rem)] max-h-[90dvh] overflow-y-auto">
                                        <DialogHeader>
                                            <DialogTitle>Approve this Leave request?</DialogTitle>
                                            <DialogDescription>
                                                Approve {request.requestedDays} {request.requestedDays === '1.00' ? 'day' : 'days'} of {request.leaveType} from {request.startDate} to {request.endDate}. The canonical approval process will record the Leave usage. This does not add another approval level.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form onSubmit={submitApproval} className="grid gap-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="approval-review-note">Internal review note <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                                <textarea id="approval-review-note" rows={4} maxLength={5000} value={approval.data.review_note} onChange={(event) => approval.setData('review_note', event.target.value)} aria-describedby="approval-note-help approval-note-error" className="min-h-28 w-full rounded-md border bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50" />
                                                <p id="approval-note-help" className="text-xs text-muted-foreground">This note is kept separate from the Employee’s request reason and note.</p>
                                                <InputError id="approval-note-error" message={approval.errors.review_note} />
                                            </div>
                                            <DialogFooter>
                                                <DialogClose asChild><Button type="button" variant="outline" disabled={approval.processing} className="min-h-11">Keep pending</Button></DialogClose>
                                                <Button type="submit" disabled={approval.processing} className="min-h-11 w-full sm:w-auto">
                                                    {approval.processing ? 'Approving…' : 'Confirm approval'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                            {request.decisions.canReject && request.decisions.rejectUrl && (
                                <Dialog open={rejectionOpen} onOpenChange={setRejectionOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="destructive" className="min-h-11 w-full sm:w-auto">Reject request</Button>
                                    </DialogTrigger>
                                    <DialogContent className="w-[calc(100vw-2rem)] max-h-[90dvh] overflow-y-auto">
                                        <DialogHeader>
                                            <DialogTitle>Reject this Leave request?</DialogTitle>
                                            <DialogDescription>
                                                Reject {request.requestedDays} {request.requestedDays === '1.00' ? 'day' : 'days'} of {request.leaveType} from {request.startDate} to {request.endDate}. The pending reservation will be released. A review reason is required and remains separate from Employee-submitted notes.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form onSubmit={submitRejection} className="grid gap-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="rejection-review-note">Internal review reason <span aria-hidden="true">*</span></Label>
                                                <textarea id="rejection-review-note" rows={4} required minLength={5} maxLength={5000} value={rejection.data.review_note} onChange={(event) => rejection.setData('review_note', event.target.value)} aria-describedby="rejection-note-help rejection-note-error" className="min-h-28 w-full rounded-md border bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50" />
                                                <p id="rejection-note-help" className="text-xs text-muted-foreground">Enter at least five characters. This is an internal reviewer note.</p>
                                                <InputError id="rejection-note-error" message={rejection.errors.review_note} />
                                            </div>
                                            <DialogFooter>
                                                <DialogClose asChild><Button type="button" variant="outline" disabled={rejection.processing} className="min-h-11">Keep pending</Button></DialogClose>
                                                <Button type="submit" variant="destructive" disabled={rejection.processing || rejection.data.review_note.trim().length < 5} className="min-h-11 w-full sm:w-auto">
                                                    {rejection.processing ? 'Rejecting…' : 'Confirm rejection'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}

function Field({ label, value }: { label: string; value: string }) {
    return <div className="grid min-w-0 gap-1"><span className="text-sm text-muted-foreground">{label}</span><span className="break-words text-sm font-medium">{value}</span></div>;
}

function TextField({ label, value }: { label: string; value: string }) {
    return <div className="grid min-w-0 gap-1 sm:col-span-2"><span className="text-sm text-muted-foreground">{label}</span><p className="break-words whitespace-pre-wrap text-sm">{value}</p></div>;
}
