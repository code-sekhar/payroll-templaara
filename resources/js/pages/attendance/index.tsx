import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type PageLink = { url: string | null; label: string; active: boolean };
type Row = {
    id: number; attendance_date: string; employee: string; employee_code: string; department: string | null; branch: string | null;
    shift: string | null; schedule: string | null; first_check_in: string | null; last_check_out: string | null;
    worked_minutes: number; expected_minutes: number; late_minutes: number; early_leave_minutes: number; overtime_minutes: number;
    status: string; leave_type: string | null; leave_amount: string | null; record_state: string; regularization_status: string | null;
};
type Option = { id: number; name: string };
type Filters = { date_from: string; date_to: string; search: string; department_id: string; designation_id: string; branch_id: string; status: string; record_state: string; regularization_status: string };
type Summary = { total: number; present: number; absent: number; half_day: number; on_leave: number; partial_leave: number; late: number; needs_review: number; pending_regularization: number };

const duration = (minutes: number) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
const label = (value: string | null) => value ? value.replaceAll('_', ' ') : '—';

export default function AttendanceIndex({ records, summary, filters, options, company }: {
    records: { data: Row[]; links: PageLink[]; total: number };
    summary: Summary;
    filters: Filters;
    options: { statuses: { value: string; label: string }[]; record_states: { value: string; label: string }[]; regularization_statuses: string[]; departments: Option[]; designations: Option[]; branches: Option[] };
    company: { id: number; name: string; timezone: string };
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/attendance`;
    const submit = (event: FormEvent) => { event.preventDefault(); router.get(baseUrl, values, { preserveState: true, replace: true }); };
    const cards: [string, number][] = [['Total records', summary.total], ['Present', summary.present], ['Absent', summary.absent], ['Half day', summary.half_day], ['On leave', summary.on_leave], ['Partial leave', summary.partial_leave], ['Late', summary.late], ['Needs review', summary.needs_review], ['Pending regularization', summary.pending_regularization]];

    return <AppLayout breadcrumbs={[{ title: 'Attendance', href: baseUrl }]}>
        <Head title="Attendance" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">Attendance</h1><p className="mt-1 text-sm text-muted-foreground">Materialized attendance for {company.name}. Dates use {company.timezone}.</p></header>
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map(([title, value]) => <article key={title} className="rounded-xl border bg-card p-4"><p className="text-sm text-muted-foreground">{title}</p><p className="mt-2 text-2xl font-semibold">{value}</p></article>)}</section>
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-4">
                <label className="grid gap-1 text-xs text-muted-foreground">From<input type="date" value={values.date_from} onChange={(event) => setValues({ ...values, date_from: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">To<input type="date" value={values.date_to} onChange={(event) => setValues({ ...values, date_to: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Employee search<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or employee code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Department<select value={values.department_id} onChange={(event) => setValues({ ...values, department_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All departments</option>{options.departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Designation<select value={values.designation_id} onChange={(event) => setValues({ ...values, designation_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All designations</option>{options.designations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Branch<select value={values.branch_id} onChange={(event) => setValues({ ...values, branch_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All branches</option>{options.branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Attendance status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option>{options.statuses.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Record state<select value={values.record_state} onChange={(event) => setValues({ ...values, record_state: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All states</option>{options.record_states.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Regularization<select value={values.regularization_status} onChange={(event) => setValues({ ...values, regularization_status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All requests</option>{options.regularization_statuses.map((item) => <option key={item} value={item}>{label(item)}</option>)}</select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>
            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/50 text-muted-foreground"><tr>{['Date', 'Employee', 'Department', 'Shift / schedule', 'First in', 'Last out', 'Worked / expected', 'Late', 'Early', 'Overtime', 'Status', 'Leave', 'State', 'Request', ''].map((heading) => <th key={heading} className="whitespace-nowrap px-3 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{records.data.map((record) => <tr key={record.id} className="border-t"><td className="whitespace-nowrap px-3 py-3">{record.attendance_date}</td><td className="px-3 py-3"><p className="font-medium">{record.employee}</p><p className="text-xs text-muted-foreground">{record.employee_code}</p></td><td className="px-3 py-3">{record.department ?? '—'}</td><td className="px-3 py-3">{record.shift ?? record.schedule ?? '—'}</td><td className="whitespace-nowrap px-3 py-3">{record.first_check_in ?? '—'}</td><td className="whitespace-nowrap px-3 py-3">{record.last_check_out ?? '—'}</td><td className="whitespace-nowrap px-3 py-3">{duration(record.worked_minutes)} / {duration(record.expected_minutes)}</td><td className="px-3 py-3">{duration(record.late_minutes)}</td><td className="px-3 py-3">{duration(record.early_leave_minutes)}</td><td className="px-3 py-3">{duration(record.overtime_minutes)}</td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{label(record.status)}</span></td><td className="px-3 py-3">{record.leave_type ? <span className="text-sm">{record.leave_type} · {record.leave_amount}</span> : '—'}</td><td className="px-3 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{label(record.record_state)}</span></td><td className="px-3 py-3 capitalize">{label(record.regularization_status)}</td><td className="whitespace-nowrap px-3 py-3"><Link className="underline underline-offset-4" href={`${baseUrl}/${record.id}`}>Details</Link></td></tr>)}</tbody>
                </table></div>
                {records.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No materialized attendance records match these filters. Missing records are not treated as absent.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{records.total} records</span><nav className="flex gap-1">{records.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</Link> : <span key={index} className="px-2 py-1 opacity-50">{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</span>)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
