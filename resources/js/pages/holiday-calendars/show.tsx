import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type HolidayRow = { id: number; date: string; name: string; description: string | null; type: string; status: 'active' | 'inactive'; is_optional: boolean; created_by: string | null };
type PageLink = { url: string | null; label: string; active: boolean };

export default function HolidayCalendarShow({ calendar, holidays, filters, company, canUpdateCalendar, canDeleteCalendar, canActivateCalendar, canDeactivateCalendar, canCreateHoliday, canUpdateHoliday, canDeleteHoliday, holidayTypes, holidayStatuses }: {
    calendar: { id: number; name: string; year: number; description: string | null; status: 'active' | 'inactive'; created_at: string };
    holidays: { data: HolidayRow[]; links: PageLink[]; total: number };
    filters: { search: string; status: string; type: string; optional: string };
    company: { id: number; name: string };
    canUpdateCalendar: boolean;
    canDeleteCalendar: boolean;
    canActivateCalendar: boolean;
    canDeactivateCalendar: boolean;
    canCreateHoliday: boolean;
    canUpdateHoliday: boolean;
    canDeleteHoliday: boolean;
    holidayTypes: { value: string; label: string }[];
    holidayStatuses: { value: string; label: string }[];
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/holiday-calendars`;
    const calendarUrl = `${baseUrl}/${calendar.id}`;
    const holidayUrl = `${calendarUrl}/holidays`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(calendarUrl, values, { preserveState: true, replace: true });
    };
    const archiveHoliday = (holiday: HolidayRow) => {
        if (window.confirm(`Archive ${holiday.name}?`)) {
            router.delete(`${holidayUrl}/${holiday.id}`);
        }
    };
    const archiveCalendar = () => {
        if (window.confirm(`Archive ${calendar.name}? Its holiday history will be retained.`)) {
            router.delete(calendarUrl);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Holiday calendars', href: baseUrl }, { title: calendar.name, href: calendarUrl }]}>
        <Head title={`${calendar.name} · ${calendar.year}`} />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div><p className="text-sm text-muted-foreground">{company.name} · {calendar.year}</p><h1 className="mt-1 text-2xl font-semibold">{calendar.name}</h1>{calendar.description && <p className="mt-2 max-w-2xl text-sm text-muted-foreground">{calendar.description}</p>}</div>
                <div className="flex flex-wrap gap-2">
                    {canCreateHoliday && calendar.status === 'active' && <Button asChild><Link href={`${holidayUrl}/create`}>Add holiday</Link></Button>}
                    {canUpdateCalendar && <Button variant="outline" asChild><Link href={`${calendarUrl}/edit`}>Edit calendar</Link></Button>}
                    {calendar.status === 'active' && canDeactivateCalendar && <Button variant="outline" onClick={() => router.patch(`${calendarUrl}/deactivate`)}>Deactivate</Button>}
                    {calendar.status === 'inactive' && canActivateCalendar && <Button variant="outline" onClick={() => router.patch(`${calendarUrl}/activate`)}>Activate</Button>}
                    {canDeleteCalendar && <Button variant="destructive" onClick={archiveCalendar}>Archive</Button>}
                </div>
            </header>

            <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-3">
                <div><p className="text-xs text-muted-foreground">Calendar year</p><p className="mt-1 font-medium">{calendar.year}</p></div>
                <div><p className="text-xs text-muted-foreground">Status</p><p className="mt-1 font-medium capitalize">{calendar.status}</p></div>
                <div><p className="text-xs text-muted-foreground">Holiday dates</p><p className="mt-1 font-medium">{holidays.total}</p></div>
            </section>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_10rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Holiday name" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Type<select value={values.type} onChange={(event) => setValues({ ...values, type: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All types</option>{holidayTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option>{holidayStatuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Optional<select value={values.optional} onChange={(event) => setValues({ ...values, optional: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All</option><option value="1">Optional</option><option value="0">Required</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Filter</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr>{['Date', 'Holiday', 'Type', 'Optional', 'Status', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{holidays.data.map((holiday) => <tr key={holiday.id} className="border-t">
                        <td className="whitespace-nowrap px-4 py-3">{holiday.date}</td>
                        <td className="px-4 py-3"><p className="font-medium">{holiday.name}</p>{holiday.description && <p className="max-w-md text-xs text-muted-foreground">{holiday.description}</p>}</td>
                        <td className="px-4 py-3 capitalize">{holiday.type.replaceAll('_', ' ')}</td>
                        <td className="px-4 py-3">{holiday.is_optional ? 'Yes' : 'No'}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{holiday.status}</span></td>
                        <td className="px-4 py-3"><div className="flex gap-3 whitespace-nowrap">
                            {canUpdateHoliday && <Link href={`${holidayUrl}/${holiday.id}/edit`} className="underline underline-offset-4">Edit</Link>}
                            {canDeleteHoliday && <button type="button" className="text-destructive hover:underline" onClick={() => archiveHoliday(holiday)}>Archive</button>}
                        </div></td>
                    </tr>)}</tbody>
                </table></div>
                {holidays.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No holidays match these filters.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{holidays.total} holidays</span><nav className="flex gap-1">{holidays.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
