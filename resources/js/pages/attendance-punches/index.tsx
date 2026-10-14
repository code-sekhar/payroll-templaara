import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type PageLink = { url: string | null; label: string; active: boolean };
type PunchRow = {
    id: number;
    employee: string;
    employee_code: string;
    punch_type: 'check_in' | 'check_out';
    punched_at_local: string;
    timezone: string;
    source: string;
    has_location: boolean;
    location_result: string | null;
    worksite_name: string | null;
    coordinates: string | null;
    distance_meters: string | null;
    radius_meters: number | null;
    device_identifier: string | null;
    external_reference: string | null;
    created_by: string;
};
type Option = { value: string; label: string };
type Filters = { search: string; punch_type: string; source: string; date_from: string; date_to: string };

export default function AttendancePunchesIndex({ punches, filters, punchTypes, sources, company, canCreateManual }: {
    punches: { data: PunchRow[]; links: PageLink[]; total: number };
    filters: Filters;
    punchTypes: Option[];
    sources: Option[];
    company: { id: number; name: string; timezone: string };
    canCreateManual: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/attendance-punches`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };

    return <AppLayout breadcrumbs={[{ title: 'Attendance punches', href: baseUrl }]}>
        <Head title="Attendance punches" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Attendance punches</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Immutable raw events for {company.name}.</p>
                </div>
                {canCreateManual && <Button asChild><Link href={`${baseUrl}/manual/create`}>Record manual punch</Link></Button>}
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_12rem_12rem_11rem_11rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search employee<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or employee code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Punch type<select value={values.punch_type} onChange={(event) => setValues({ ...values, punch_type: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All types</option>{punchTypes.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Source<select value={values.source} onChange={(event) => setValues({ ...values, source: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All sources</option>{sources.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">From<input type="date" value={values.date_from} onChange={(event) => setValues({ ...values, date_from: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">To<input type="date" value={values.date_to} onChange={(event) => setValues({ ...values, date_to: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <Button type="submit" variant="outline" className="self-end">Apply</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Punch', 'Recorded time', 'Source', 'Location audit', 'Device / Reference', 'Created by'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{punches.data.map((punch) => <tr key={punch.id} className="border-t">
                        <td className="px-4 py-3"><p className="font-medium">{punch.employee}</p><p className="text-xs text-muted-foreground">{punch.employee_code}</p></td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs">{punch.punch_type === 'check_in' ? 'Check in' : 'Check out'}</span></td>
                        <td className="whitespace-nowrap px-4 py-3"><p>{punch.punched_at_local}</p><p className="text-xs text-muted-foreground">{punch.timezone}</p></td>
                        <td className="px-4 py-3 capitalize">{punch.source}</td>
                        <td className="px-4 py-3"><p className="capitalize">{punch.location_result?.replaceAll('_', ' ') ?? (punch.has_location ? 'Location recorded' : '—')}</p>{punch.worksite_name && <p className="text-xs">{punch.worksite_name}</p>}{punch.coordinates && <p className="break-all text-xs text-muted-foreground">{punch.coordinates}</p>}{punch.distance_meters && <p className="text-xs text-muted-foreground">{punch.distance_meters} m of {punch.radius_meters} m allowed</p>}</td>
                        <td className="px-4 py-3"><p>{punch.device_identifier ?? '—'}</p><p className="text-xs text-muted-foreground">{punch.external_reference ?? ''}</p></td>
                        <td className="px-4 py-3">{punch.created_by}</td>
                    </tr>)}</tbody>
                </table></div>
                {punches.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No attendance punches match these filters.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{punches.total} punch events</span><nav className="flex gap-1">{punches.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</Link> : <span key={index} className="px-2 py-1 opacity-50">{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</span>)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
