import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ShiftRow = {
    id: number;
    name: string;
    code: string;
    start_time: string;
    end_time: string;
    break_duration_minutes: number;
    grace_period_minutes: number;
    is_overnight: boolean;
    duration_minutes: number;
    net_duration_minutes: number;
    status: 'active' | 'inactive';
};
type PageLink = { url: string | null; label: string; active: boolean };

const formatDuration = (minutes: number): string => `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}`;

export default function ShiftsIndex({ shifts, filters, company, canCreate, canUpdate, canDelete, canActivate, canDeactivate }: {
    shifts: { data: ShiftRow[]; links: PageLink[]; total: number };
    filters: { search: string; status: string };
    company: { id: number; name: string; timezone: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const baseUrl = `/companies/${company.id}/shifts`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, { search, status }, { preserveState: true, replace: true });
    };
    const remove = (shift: ShiftRow) => {
        if (window.confirm(`Delete ${shift.name}? This cannot be undone.`)) {
            router.delete(`${baseUrl}/${shift.id}`);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Shifts', href: baseUrl }]}>
        <Head title="Shifts" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Shifts</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Reusable shift definitions for {company.name}. Times use {company.timezone}.</p>
                </div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create shift</Link></Button>}
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Shift name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={status} onChange={(event) => setStatus(event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr>
                            {['Shift', 'Hours', 'Overnight', 'Break', 'Grace period', 'Status', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}
                        </tr></thead>
                        <tbody>{shifts.data.map((shift) => <tr key={shift.id} className="border-t">
                            <td className="px-4 py-3"><p className="font-medium">{shift.name}</p><p className="text-xs text-muted-foreground">{shift.code}</p></td>
                            <td className="whitespace-nowrap px-4 py-3">{shift.start_time}–{shift.end_time}<p className="text-xs text-muted-foreground">{formatDuration(shift.duration_minutes)} scheduled</p></td>
                            <td className="px-4 py-3">{shift.is_overnight ? 'Yes' : 'No'}</td>
                            <td className="px-4 py-3">{formatDuration(shift.break_duration_minutes)}</td>
                            <td className="px-4 py-3">{shift.grace_period_minutes} min</td>
                            <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{shift.status}</span></td>
                            <td className="px-4 py-3"><div className="flex flex-wrap items-center gap-3 whitespace-nowrap">
                                {canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${shift.id}/edit`}>Edit</Link>}
                                {shift.status === 'active' && canDeactivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${shift.id}/deactivate`)}>Deactivate</button>}
                                {shift.status === 'inactive' && canActivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${shift.id}/activate`)}>Activate</button>}
                                {canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => remove(shift)}>Delete</button>}
                            </div></td>
                        </tr>)}</tbody>
                    </table>
                </div>
                {shifts.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No shifts found. Adjust the filters or create a shift.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground">
                    <span>{shifts.total} shifts</span>
                    <nav className="flex gap-1">{shifts.links.map((link, index) => link.url
                        ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
                </footer>
            </section>
        </main>
    </AppLayout>;
}
