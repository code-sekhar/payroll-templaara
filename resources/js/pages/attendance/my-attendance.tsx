import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Row = { id: number; attendance_date: string; worked_minutes: number; expected_minutes: number; first_check_in: string | null; last_check_out: string | null; late_minutes: number; early_leave_minutes: number; overtime_minutes: number; status: string; leave_type: string | null; leave_amount: string | null; record_state: string; shift: string | null; schedule: string | null; regularization_status: string | null };
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { date_from: string; date_to: string; status: string };
const duration = (minutes: number) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
const label = (value: string) => value.replaceAll('_', ' ');

export default function MyAttendance({ company, today, todayRecord, nextPunchType, selfPunchUrl, records, filters, statuses }: {
    company: { id: number; name: string; timezone: string };
    today: string;
    todayRecord: Row | null;
    nextPunchType: 'check_in' | 'check_out';
    selfPunchUrl: string;
    records: { data: Row[]; links: PageLink[]; total: number };
    filters: Filters;
    statuses: { value: string; label: string }[];
}) {
    const [values, setValues] = useState(filters);
    const [punchMessage, setPunchMessage] = useState('');
    const punch = useForm({ punch_type: nextPunchType });
    const baseUrl = `/companies/${company.id}/my-attendance`;
    const filter = (event: FormEvent) => { event.preventDefault(); router.get(baseUrl, values, { preserveState: true, replace: true }); };
    const submitPunch = () => punch.post(selfPunchUrl, {
        preserveScroll: true,
        onSuccess: () => {
            setPunchMessage('Attendance punch recorded.');
            punch.setData('punch_type', nextPunchType === 'check_in' ? 'check_out' : 'check_in');
        },
    });

    return <AppLayout breadcrumbs={[{ title: 'My attendance', href: baseUrl }]}>
        <Head title="My attendance" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">My attendance</h1><p className="mt-1 text-sm text-muted-foreground">Your attendance history for {company.name} · {company.timezone}</p></header>
            <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-[1fr_auto] sm:items-center">
                <div><p className="text-sm text-muted-foreground">Today · {today}</p>{todayRecord ? <><h2 className="mt-1 text-xl font-semibold capitalize">{label(todayRecord.status)} <span className="text-sm font-normal text-muted-foreground">· {label(todayRecord.record_state)}</span></h2><p className="mt-2 text-sm text-muted-foreground">Expected shift: {todayRecord.shift ?? todayRecord.schedule ?? '—'} · First in: {todayRecord.first_check_in ?? '—'} · Last out: {todayRecord.last_check_out ?? '—'}</p>{todayRecord.leave_type && <p className="mt-1 text-sm">Approved leave: {todayRecord.leave_type} · {todayRecord.leave_amount}</p>}<p className="mt-1 text-sm">Worked {duration(todayRecord.worked_minutes)} / expected {duration(todayRecord.expected_minutes)} · Late {duration(todayRecord.late_minutes)} · Early {duration(todayRecord.early_leave_minutes)} · Overtime {duration(todayRecord.overtime_minutes)}</p></> : <><h2 className="mt-1 text-xl font-semibold">Not calculated yet</h2><p className="mt-1 text-sm text-muted-foreground">Your raw punch can be saved before a daily attendance record is materialized.</p></>}</div>
                <div className="flex flex-col gap-2"><Button onClick={submitPunch} disabled={punch.processing}>{punch.processing ? 'Recording…' : nextPunchType === 'check_in' ? 'Check in' : 'Check out'}</Button><InputError message={punch.errors.punch_type} />{punchMessage && <p role="status" className="text-center text-sm text-emerald-700 dark:text-emerald-300">{punchMessage}</p>}<p className="text-center text-xs text-muted-foreground">Recorded using server time</p></div>
            </section>
            <form onSubmit={filter} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label className="grid gap-1 text-xs text-muted-foreground">From<input type="date" value={values.date_from} onChange={(event) => setValues({ ...values, date_from: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">To<input type="date" value={values.date_to} onChange={(event) => setValues({ ...values, date_to: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option>{statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}</select></label>
                <Button type="submit" variant="outline" className="self-end">Apply</Button>
            </form>
            <section className="overflow-hidden rounded-xl border"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/50 text-muted-foreground"><tr>{['Date', 'Status', 'Leave', 'Shift', 'First in', 'Last out', 'Worked / expected', 'Late', 'Early leave', 'Overtime', 'Regularization', ''].map((item) => <th key={item} className="whitespace-nowrap px-4 py-3 font-medium">{item}</th>)}</tr></thead><tbody>{records.data.map((record) => <tr key={record.id} className="border-t"><td className="px-4 py-3">{record.attendance_date}</td><td className="px-4 py-3 capitalize">{label(record.status)} · {label(record.record_state)}</td><td className="px-4 py-3">{record.leave_type ? `${record.leave_type} · ${record.leave_amount}` : '—'}</td><td className="px-4 py-3">{record.shift ?? record.schedule ?? '—'}</td><td className="whitespace-nowrap px-4 py-3">{record.first_check_in ?? '—'}</td><td className="whitespace-nowrap px-4 py-3">{record.last_check_out ?? '—'}</td><td className="whitespace-nowrap px-4 py-3">{duration(record.worked_minutes)} / {duration(record.expected_minutes)}</td><td className="px-4 py-3">{duration(record.late_minutes)}</td><td className="px-4 py-3">{duration(record.early_leave_minutes)}</td><td className="px-4 py-3">{duration(record.overtime_minutes)}</td><td className="px-4 py-3 capitalize">{record.regularization_status?.replaceAll('_', ' ') ?? '—'}</td><td className="px-4 py-3"><Link href={`${baseUrl}/${record.id}`} className="underline underline-offset-4">Details</Link></td></tr>)}</tbody></table></div>
                {records.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No materialized attendance records for this range. No record is not treated as absence.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{records.total} records</span><nav className="flex gap-1">{records.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</Link> : <span key={index} className="px-2 py-1 opacity-50">{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</span>)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
