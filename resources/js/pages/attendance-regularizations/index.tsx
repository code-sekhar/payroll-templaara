import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type PageLink = { url: string | null; label: string; active: boolean };
type Option = { value: string; label: string };
type RequestRow = { id: number; employee: string; employee_code: string; attendance_date: string; request_type: string; status: string; reason: string; requester: string; requested_at: string; item_count: number; can_cancel: boolean };
type Filters = { search: string; date_from: string; date_to: string; request_type: string; status: string; requester: string };

export default function AttendanceRegularizationsIndex({ requests, filters, types, statuses, company, canCreate, canCreateSelf }: {
    requests: { data: RequestRow[]; links: PageLink[]; total: number };
    filters: Filters;
    types: Option[];
    statuses: Option[];
    company: { id: number; name: string };
    canCreate: boolean;
    canCreateSelf: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/attendance-regularizations`;
    const submit = (event: FormEvent) => { event.preventDefault(); router.get(baseUrl, values, { preserveState: true, replace: true }); };

    return <AppLayout breadcrumbs={[{ title: 'Attendance regularization', href: baseUrl }]}>
        <Head title="Attendance regularization" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold">Attendance regularization</h1><p className="mt-1 text-sm text-muted-foreground">Review correction requests for {company.name}.</p></div>
                <div className="flex gap-2">
                    {canCreateSelf && <Button variant="outline" asChild><Link href={`${baseUrl}/self/create`}>Submit my request</Link></Button>}
                    {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>New request</Link></Button>}
                </div>
            </header>
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_11rem_11rem_12rem_12rem_minmax(10rem,1fr)_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Employee<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or employee code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">From<input type="date" value={values.date_from} onChange={(event) => setValues({ ...values, date_from: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">To<input type="date" value={values.date_to} onChange={(event) => setValues({ ...values, date_to: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Request type<select value={values.request_type} onChange={(event) => setValues({ ...values, request_type: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All types</option>{types.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option>{statuses.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Requester<input value={values.requester} onChange={(event) => setValues({ ...values, requester: event.target.value })} placeholder="Requester name" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <Button type="submit" variant="outline" className="self-end">Apply</Button>
            </form>
            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Attendance date', 'Type', 'Status', 'Reason', 'Requester', 'Requested', ''].map((label) => <th key={label} className="whitespace-nowrap px-4 py-3 font-medium">{label}</th>)}</tr></thead>
                    <tbody>{requests.data.map((item) => <tr key={item.id} className="border-t">
                        <td className="px-4 py-3"><p className="font-medium">{item.employee}</p><p className="text-xs text-muted-foreground">{item.employee_code}</p></td>
                        <td className="whitespace-nowrap px-4 py-3">{item.attendance_date}</td><td className="px-4 py-3">{item.request_type.replaceAll('_', ' ')}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{item.status}</span></td>
                        <td className="max-w-xs truncate px-4 py-3" title={item.reason}>{item.reason}</td><td className="px-4 py-3">{item.requester}</td><td className="whitespace-nowrap px-4 py-3">{item.requested_at}</td>
                        <td className="whitespace-nowrap px-4 py-3"><Link className="underline underline-offset-4" href={`${baseUrl}/${item.id}`}>{item.status === 'pending' ? 'Review request' : 'View'} ({item.item_count})</Link>{item.can_cancel && <button className="ml-3 underline underline-offset-4" onClick={() => { if (window.confirm('Cancel this pending request?')) router.post(`${baseUrl}/${item.id}/cancel`); }}>Cancel</button>}</td>
                    </tr>)}</tbody></table></div>
                {requests.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">{filters.status === 'pending' ? 'No pending attendance correction requests.' : 'No attendance regularization requests match these filters.'}</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{requests.total} requests</span><nav className="flex gap-1">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</Link> : <span key={index} className="px-2 py-1 opacity-50">{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</span>)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
