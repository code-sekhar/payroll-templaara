import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type CalendarRow = { id: number; name: string; year: number; status: 'active' | 'inactive'; holiday_count: number; created_at: string };
type PageLink = { url: string | null; label: string; active: boolean };

export default function HolidayCalendarsIndex({ calendars, filters, company, canCreate, canUpdate, canDelete, canActivate, canDeactivate }: {
    calendars: { data: CalendarRow[]; links: PageLink[]; total: number };
    filters: { search: string; year: number | ''; status: string };
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/holiday-calendars`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (calendar: CalendarRow) => {
        if (window.confirm(`Archive ${calendar.name}? Its holiday history will be retained.`)) {
            router.delete(`${baseUrl}/${calendar.id}`);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Holiday calendars', href: baseUrl }]}>
        <Head title="Holiday calendars" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold">Holiday calendars</h1><p className="mt-1 text-sm text-muted-foreground">Manage holiday dates for {company.name}.</p></div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create calendar</Link></Button>}
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_10rem_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Calendar name" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Year<input type="number" min="1900" max="2200" value={values.year} onChange={(event) => setValues({ ...values, year: event.target.value === '' ? '' : Number(event.target.value) })} placeholder="Any year" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr>{['Calendar', 'Year', 'Holidays', 'Status', 'Created', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{calendars.data.map((calendar) => <tr key={calendar.id} className="border-t">
                        <td className="px-4 py-3"><Link href={`${baseUrl}/${calendar.id}`} className="font-medium underline-offset-4 hover:underline">{calendar.name}</Link></td>
                        <td className="px-4 py-3">{calendar.year}</td>
                        <td className="px-4 py-3">{calendar.holiday_count}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{calendar.status}</span></td>
                        <td className="whitespace-nowrap px-4 py-3">{new Date(`${calendar.created_at}T00:00:00`).toLocaleDateString()}</td>
                        <td className="px-4 py-3"><div className="flex flex-wrap items-center gap-3 whitespace-nowrap">
                            <Link className="underline underline-offset-4" href={`${baseUrl}/${calendar.id}`}>View</Link>
                            {canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${calendar.id}/edit`}>Edit</Link>}
                            {calendar.status === 'active' && canDeactivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${calendar.id}/deactivate`)}>Deactivate</button>}
                            {calendar.status === 'inactive' && canActivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${calendar.id}/activate`)}>Activate</button>}
                            {canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(calendar)}>Archive</button>}
                        </div></td>
                    </tr>)}</tbody>
                </table></div>
                {calendars.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No holiday calendars found. Adjust the filters or create a calendar.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{calendars.total} calendars</span><nav className="flex gap-1">{calendars.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
