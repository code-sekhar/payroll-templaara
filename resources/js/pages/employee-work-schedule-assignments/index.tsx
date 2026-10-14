import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ScheduleOption = { id: number; name: string; code: string; status: 'active' | 'inactive'; deleted: boolean };
type AssignmentRow = {
    id: number;
    employee: { id: number; name: string; code: string };
    schedule: { id: number; name: string; code: string };
    effective_from: string;
    effective_to: string | null;
    status: 'active' | 'inactive';
    period: 'current' | 'historical' | 'future';
    created_by: string | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search: string; employee: string; schedule_id: string; status: string; period: string };

const displayDate = (date: string | null): string => date ? new Date(`${date}T00:00:00`).toLocaleDateString() : 'Present';
const periodLabel: Record<AssignmentRow['period'], string> = { current: 'Current', historical: 'Historical', future: 'Future' };

export default function EmployeeWorkScheduleAssignmentsIndex({ assignments, filters, schedules, company, canCreate, canUpdate, canDelete }: {
    assignments: { data: AssignmentRow[]; links: PageLink[]; total: number };
    filters: Filters;
    schedules: ScheduleOption[];
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/work-schedule-assignments`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (assignment: AssignmentRow) => {
        if (window.confirm(`Archive ${assignment.employee.name}'s ${assignment.schedule.name} schedule assignment? The record will be retained in history.`)) {
            router.delete(`${baseUrl}/${assignment.id}`);
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Employee schedule assignments', href: baseUrl }]}>
            <Head title="Employee schedule assignments" />
            <main className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Employee schedule assignments</h1>
                        <p className="mt-1 text-sm text-muted-foreground">Effective dated work schedule history for {company.name}.</p>
                    </div>
                    {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Assign a schedule</Link></Button>}
                </header>

                <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_14rem_11rem_10rem_auto]">
                    <label className="grid gap-1 text-xs text-muted-foreground">Search employee or schedule<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                    <label className="grid gap-1 text-xs text-muted-foreground">Employee filter<input value={values.employee} onChange={(event) => setValues({ ...values, employee: event.target.value })} placeholder="Employee name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                    <label className="grid gap-1 text-xs text-muted-foreground">Work schedule<select value={values.schedule_id} onChange={(event) => setValues({ ...values, schedule_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All schedules</option>{schedules.map((schedule) => <option key={schedule.id} value={schedule.id}>{schedule.name}{schedule.deleted ? ' · archived' : ''}</option>)}</select></label>
                    <label className="grid gap-1 text-xs text-muted-foreground">Assignment status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                    <label className="grid gap-1 text-xs text-muted-foreground">Period<select value={values.period} onChange={(event) => setValues({ ...values, period: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All periods</option><option value="current">Current</option><option value="historical">Historical</option><option value="future">Future</option></select></label>
                    <Button type="submit" variant="outline" className="self-end">Apply</Button>
                </form>

                <section className="overflow-hidden rounded-xl border">
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Work schedule', 'Effective period', 'Period', 'Status', 'Created by', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                        <tbody>{assignments.data.map((assignment) => <tr key={assignment.id} className="border-t">
                            <td className="px-4 py-3"><p className="font-medium">{assignment.employee.name}</p><p className="text-xs text-muted-foreground">{assignment.employee.code}</p></td>
                            <td className="px-4 py-3"><p>{assignment.schedule.name}</p><p className="text-xs text-muted-foreground">{assignment.schedule.code}</p></td>
                            <td className="whitespace-nowrap px-4 py-3">{displayDate(assignment.effective_from)} → {displayDate(assignment.effective_to)}</td>
                            <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs">{periodLabel[assignment.period]}</span></td>
                            <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{assignment.status}</span></td>
                            <td className="px-4 py-3">{assignment.created_by ?? '—'}</td>
                            <td className="px-4 py-3"><div className="flex items-center gap-3 whitespace-nowrap">{canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${assignment.id}/edit`}>Edit</Link>}{canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(assignment)}>Archive</button>}</div></td>
                        </tr>)}</tbody>
                    </table></div>
                    {assignments.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No employee schedule assignments match these filters.</p>}
                    <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{assignments.total} assignments</span><nav className="flex gap-1">{assignments.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
                </section>
            </main>
        </AppLayout>
    );
}
