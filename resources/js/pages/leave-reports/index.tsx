import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string };
type EmployeeOption = { id: number; name: string; code: string };
type StatusOption = { value: string; label: string };
type Filters = { search: string; status: string; leave_type_id: string; employee_id: string; department_id: string; branch_id: string; date_from: string; date_to: string };
type PageLink = { url: string | null; label: string; active: boolean };
type Row = {
    id: number; employee: { id: number | null; name: string; code: string }; leave_type: Option; start_date: string; end_date: string;
    requested_days: string; status: string; requested_at: string | null; reviewer: string | null; reviewed_at: string | null;
    canceller: string | null; cancelled_at: string | null; cancellation_reason: string | null; url: string;
};
type Summary = { total: number; pending: number; approved: number; rejected: number; cancelled: number };

export default function LeaveReportsIndex({ company, requests, summary, filters, options, exportUrl }: {
    company: { id: number; name: string };
    requests: { data: Row[]; links: PageLink[]; total: number };
    summary: Summary;
    filters: Filters;
    options: { leaveTypes: Option[]; employees: EmployeeOption[]; departments: Option[]; branches: Option[]; statuses: StatusOption[] };
    exportUrl: string;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/leave-reports`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const clear = () => {
        const empty: Filters = { search: '', status: '', leave_type_id: '', employee_id: '', department_id: '', branch_id: '', date_from: '', date_to: '' };
        setValues(empty);
        router.get(baseUrl, {}, { preserveState: true, replace: true });
    };
    const cards: [string, number][] = [
        ['Total', summary.total], ['Pending', summary.pending], ['Approved', summary.approved], ['Rejected', summary.rejected], ['Cancelled', summary.cancelled],
    ];
    const exportParameters = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (key !== 'page' && value.trim() !== '') exportParameters.set(key, value);
    });
    const exportHref = exportParameters.size > 0 ? `${exportUrl}?${exportParameters.toString()}` : exportUrl;

    return <AppLayout breadcrumbs={[{ title: 'Leave reports', href: baseUrl }]}>
        <Head title="Leave reports" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header>
                <div className="flex flex-wrap items-end justify-between gap-4"><div><h1 className="text-2xl font-semibold">Leave reports</h1><p className="mt-1 text-sm text-muted-foreground">Leave request activity for {company.name}. Summary counts follow the selected filters except status.</p></div><Button asChild variant="outline"><a href={exportHref}>Export CSV</a></Button></div>
            </header>
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {cards.map(([title, count]) => <article key={title} className="rounded-xl border bg-card p-4"><p className="text-sm text-muted-foreground">{title}</p><p className="mt-2 text-2xl font-semibold">{count}</p></article>)}
            </section>
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-4">
                <label className="grid gap-1 text-xs text-muted-foreground">Search employee<input value={values.search} onChange={event => setValues({ ...values, search: event.target.value })} placeholder="Name or employee code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={event => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option>{options.statuses.map(status => <option key={status.value} value={status.value}>{status.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Leave type<select value={values.leave_type_id} onChange={event => setValues({ ...values, leave_type_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All leave types</option>{options.leaveTypes.map(option => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Employee<select value={values.employee_id} onChange={event => setValues({ ...values, employee_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All employees</option>{options.employees.map(option => <option key={option.id} value={option.id}>{option.name} · {option.code}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Department<select value={values.department_id} onChange={event => setValues({ ...values, department_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All departments</option>{options.departments.map(option => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Branch<select value={values.branch_id} onChange={event => setValues({ ...values, branch_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All branches</option>{options.branches.map(option => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">From<input type="date" value={values.date_from} onChange={event => setValues({ ...values, date_from: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">To<input type="date" value={values.date_to} onChange={event => setValues({ ...values, date_to: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <div className="flex items-end gap-2"><Button type="submit" variant="outline">Apply filters</Button><Button type="button" variant="ghost" onClick={clear}>Clear</Button></div>
            </form>
            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/50 text-muted-foreground"><tr>{['Request', 'Employee', 'Leave type', 'Period', 'Days', 'Status', 'Requested', 'Reviewed', 'Cancelled', ''].map(heading => <th key={heading} className="whitespace-nowrap px-3 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{requests.data.map(row => <tr key={row.id} className="border-t"><td className="whitespace-nowrap px-3 py-3">#{row.id}</td><td className="px-3 py-3"><p className="font-medium">{row.employee.name}</p><p className="text-xs text-muted-foreground">{row.employee.code}</p></td><td className="px-3 py-3">{row.leave_type.name}</td><td className="whitespace-nowrap px-3 py-3">{row.start_date} – {row.end_date}</td><td className="px-3 py-3">{row.requested_days}</td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{row.status}</span></td><td className="whitespace-nowrap px-3 py-3">{row.requested_at ?? '—'}</td><td className="px-3 py-3">{row.reviewer ? <>{row.reviewer}<p className="text-xs text-muted-foreground">{row.reviewed_at}</p></> : '—'}</td><td className="px-3 py-3">{row.canceller ? <span title={row.cancellation_reason ?? undefined}>{row.canceller}<p className="text-xs text-muted-foreground">{row.cancelled_at}</p></span> : '—'}</td><td className="whitespace-nowrap px-3 py-3"><Link className="underline underline-offset-4" href={row.url}>Details</Link></td></tr>)}</tbody>
                </table></div>
                {requests.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No leave requests match the selected filters.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{requests.total} requests</span><nav aria-label="Pagination" className="flex gap-1">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</Link> : <span key={index} className="px-2 py-1 opacity-50">{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</span>)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
