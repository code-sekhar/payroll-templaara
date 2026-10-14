import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ScheduleRow = { id: number; name: string; code: string; status: 'active' | 'inactive'; working_days_count: number; weekly_minutes: number; created_at: string };
type PageLink = { url: string | null; label: string; active: boolean };

const formatDuration = (minutes: number): string => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;

export default function WorkSchedulesIndex({ schedules, filters, company, canCreate, canUpdate, canDelete, canActivate, canDeactivate }: {
    schedules: { data: ScheduleRow[]; links: PageLink[]; total: number };
    filters: { search: string; status: string };
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/work-schedules`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (schedule: ScheduleRow) => {
        if (window.confirm(`Archive ${schedule.name}? Its weekly pattern will be retained.`)) {
            router.delete(`${baseUrl}/${schedule.id}`);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Work schedules', href: baseUrl }]}>
        <Head title="Work schedules" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold">Work schedules</h1><p className="mt-1 text-sm text-muted-foreground">Reusable weekly working patterns for {company.name}.</p></div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create schedule</Link></Button>}
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Schedule name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr>{['Schedule', 'Weekly pattern', 'Status', 'Created', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{schedules.data.map((schedule) => <tr key={schedule.id} className="border-t">
                        <td className="px-4 py-3"><p className="font-medium">{schedule.name}</p><p className="text-xs text-muted-foreground">{schedule.code}</p></td>
                        <td className="px-4 py-3">{schedule.working_days_count} working days<p className="text-xs text-muted-foreground">{formatDuration(schedule.weekly_minutes)} / week</p></td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{schedule.status}</span></td>
                        <td className="whitespace-nowrap px-4 py-3">{new Date(`${schedule.created_at}T00:00:00`).toLocaleDateString()}</td>
                        <td className="px-4 py-3"><div className="flex flex-wrap items-center gap-3 whitespace-nowrap">
                            {canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${schedule.id}/edit`}>Edit</Link>}
                            {schedule.status === 'active' && canDeactivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${schedule.id}/deactivate`)}>Deactivate</button>}
                            {schedule.status === 'inactive' && canActivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${schedule.id}/activate`)}>Activate</button>}
                            {canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(schedule)}>Archive</button>}
                        </div></td>
                    </tr>)}</tbody>
                </table></div>
                {schedules.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No work schedules found. Adjust the filters or create a schedule.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{schedules.total} schedules</span><nav className="flex gap-1">{schedules.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
