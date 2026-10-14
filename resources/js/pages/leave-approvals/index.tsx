import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string };
type Row = {
    employee: { name: string; code: string | null };
    leaveType: string;
    startDate: string;
    endDate: string;
    requestedDays: string;
    status: string;
    statusLabel: string;
    requestedAt: string;
    href: string;
};
type Filters = {
    search: string;
    status: string;
    leave_type_id: string | number;
    date_from: string;
    date_to: string;
    department_id: string | number;
};
type Paginator = {
    data: Row[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

function statusVariant(status: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (status === 'approved') return 'default';
    if (status === 'rejected') return 'destructive';
    if (status === 'cancelled') return 'outline';
    return 'secondary';
}

export default function LeaveApprovalInbox({
    requests,
    filters,
    leaveTypes,
    departments,
    canFilterDepartments,
    indexUrl,
}: {
    requests: Paginator;
    filters: Filters;
    leaveTypes: Option[];
    departments: Option[];
    canFilterDepartments: boolean;
    indexUrl: string;
}) {
    const [values, setValues] = useState<Filters>(filters);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(indexUrl, values, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Leave Approval Inbox', href: indexUrl }]}>
            <Head title="Leave Approval Inbox" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">Leave Approval Inbox</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Review requests visible within your company permissions. This workspace is read-only.
                    </p>
                </header>

                <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>Employee</span>
                        <input aria-label="Search employee name or code" className="h-10 min-w-0 rounded-md border bg-background px-3" placeholder="Name or code" value={values.search} onChange={event => setValues({ ...values, search: event.target.value })} />
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>Status</span>
                        <select className="h-10 min-w-0 rounded-md border bg-background px-3" value={values.status} onChange={event => setValues({ ...values, status: event.target.value })}>
                            <option value="pending">Pending</option><option value="all">All statuses</option>
                            <option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option>
                        </select>
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>Leave type</span>
                        <select className="h-10 min-w-0 rounded-md border bg-background px-3" value={values.leave_type_id} onChange={event => setValues({ ...values, leave_type_id: event.target.value })}>
                            <option value="">All types</option>{leaveTypes.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}
                        </select>
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>From</span>
                        <input type="date" className="h-10 min-w-0 rounded-md border bg-background px-3" value={values.date_from} onChange={event => setValues({ ...values, date_from: event.target.value })} />
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>To</span>
                        <input type="date" className="h-10 min-w-0 rounded-md border bg-background px-3" value={values.date_to} onChange={event => setValues({ ...values, date_to: event.target.value })} />
                    </label>
                    {canFilterDepartments && <label className="grid min-w-0 gap-1 text-sm">
                        <span>Department</span>
                        <select className="h-10 min-w-0 rounded-md border bg-background px-3" value={values.department_id} onChange={event => setValues({ ...values, department_id: event.target.value })}>
                            <option value="">All departments</option>{departments.map(department => <option key={department.id} value={department.id}>{department.name}</option>)}
                        </select>
                    </label>}
                    <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-3 xl:col-span-6">
                        <Button type="submit" className="min-h-11">Apply filters</Button>
                        <Button type="button" variant="outline" className="min-h-11" onClick={() => router.get(indexUrl, { status: 'pending' }, { replace: true })}>Reset</Button>
                    </div>
                </form>

                <section aria-labelledby="approval-requests-heading" className="grid gap-3">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <h2 id="approval-requests-heading" className="text-lg font-semibold">Requests</h2>
                        <p className="text-sm text-muted-foreground">{requests.total} {requests.total === 1 ? 'request' : 'requests'}</p>
                    </div>
                    {requests.data.length === 0 ? (
                        <div className="rounded-xl border p-6 text-sm text-muted-foreground" role="status">No leave requests match these filters.</div>
                    ) : (
                        <ul className="grid min-w-0 gap-3">
                            {requests.data.map(row => (
                                <li key={row.href} className="min-w-0 rounded-xl border p-4 sm:p-5">
                                    <div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0 space-y-2">
                                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                                <h3 className="break-words font-semibold">{row.employee.name}</h3>
                                                <Badge variant={statusVariant(row.status)}>{row.statusLabel}</Badge>
                                            </div>
                                            <p className="break-words text-sm text-muted-foreground">{row.employee.code ? `Employee code ${row.employee.code} · ` : ''}{row.leaveType}</p>
                                            <p className="text-sm">{row.startDate} – {row.endDate} <span className="text-muted-foreground">· {row.requestedDays} {row.requestedDays === '1.00' ? 'day' : 'days'}</span></p>
                                            <p className="text-xs text-muted-foreground">Submitted {row.requestedAt}</p>
                                        </div>
                                        <Button asChild variant="outline" className="min-h-11 w-full shrink-0 sm:w-auto">
                                            <Link href={row.href}>View request</Link>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    <nav aria-label="Leave request pages" className="flex items-center justify-between gap-3 border-t pt-4 text-sm">
                        {requests.prev_page_url ? <Button asChild variant="outline" className="min-h-11"><Link href={requests.prev_page_url}>Previous</Link></Button> : <span />}
                        <span className="text-center text-muted-foreground">Page {requests.current_page} of {requests.last_page}</span>
                        {requests.next_page_url ? <Button asChild variant="outline" className="min-h-11"><Link href={requests.next_page_url}>Next</Link></Button> : <span />}
                    </nav>
                </section>
            </main>
        </AppLayout>
    );
}
