import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type EmployeeOption = { id: number; name: string; code: string };
type AssignmentRow = {
    id: number;
    employee: EmployeeOption;
    shift: { id: number; name: string; start_time: string; end_time: string };
    current_shift: string | null;
    effective_from: string;
    effective_to: string | null;
    status: 'active' | 'inactive';
};
type PageLink = { url: string | null; label: string; active: boolean };

const displayDate = (date: string | null): string => date ? new Date(`${date}T00:00:00`).toLocaleDateString() : 'Present';

export default function ShiftAssignmentsIndex({ assignments, filters, shifts, company, canCreate, canUpdate, canDelete }: {
    assignments: { data: AssignmentRow[]; links: PageLink[]; total: number };
    filters: { search: string; shift_id: string; status: string };
    shifts: { id: number; name: string; status: string }[];
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/shift-assignments`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (assignment: AssignmentRow) => {
        if (window.confirm(`Archive ${assignment.employee.name}'s ${assignment.shift.name} assignment? The history will be retained.`)) {
            router.delete(`${baseUrl}/${assignment.id}`);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Shift assignments', href: baseUrl }]}>
        <Head title="Shift assignments" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold">Shift assignments</h1><p className="mt-1 text-sm text-muted-foreground">Effective dated shift history for {company.name}.</p></div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Assign a shift</Link></Button>}
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search employee<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or employee code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Shift<select value={values.shift_id} onChange={(event) => setValues({ ...values, shift_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All shifts</option>{shifts.map((shift) => <option key={shift.id} value={shift.id}>{shift.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Assignment status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Current shift', 'Assignment shift', 'Effective period', 'Status', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{assignments.data.map((assignment) => <tr key={assignment.id} className="border-t">
                        <td className="px-4 py-3"><p className="font-medium">{assignment.employee.name}</p><p className="text-xs text-muted-foreground">{assignment.employee.code}</p></td>
                        <td className="px-4 py-3">{assignment.current_shift ?? <span className="text-muted-foreground">No current assignment</span>}</td>
                        <td className="px-4 py-3"><p>{assignment.shift.name}</p><p className="text-xs text-muted-foreground">{assignment.shift.start_time}–{assignment.shift.end_time}</p></td>
                        <td className="whitespace-nowrap px-4 py-3">{displayDate(assignment.effective_from)} → {displayDate(assignment.effective_to)}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{assignment.status}</span></td>
                        <td className="px-4 py-3"><div className="flex items-center gap-3 whitespace-nowrap">{canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${assignment.id}/edit`}>Edit</Link>}{canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(assignment)}>Archive</button>}</div></td>
                    </tr>)}</tbody>
                </table></div>
                {assignments.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No shift assignments match these filters.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{assignments.total} assignments</span><nav className="flex gap-1">{assignments.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
