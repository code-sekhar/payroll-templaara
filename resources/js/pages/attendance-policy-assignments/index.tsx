import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type PageLink = { url: string | null; label: string; active: boolean };
type PolicyOption = { id: number; name: string; code: string; status: 'active' | 'inactive'; deleted: boolean };
type AssignmentRow = {
    id: number;
    employee: { id: number; name: string; code: string };
    policy: { id: number; name: string; code: string };
    effective_from: string;
    effective_to: string | null;
    status: 'active' | 'inactive';
    period: 'current' | 'historical' | 'future';
    updated_at: string | null;
};
type Filters = { search: string; attendance_policy_id: string; status: string; effective_date: string };

const displayDate = (date: string | null): string => date
    ? new Date(`${date}T00:00:00`).toLocaleDateString()
    : 'Open-ended';

export default function EmployeeAttendancePolicyAssignmentsIndex({ assignments, filters, policies, company, canCreate, canUpdate, canDelete }: {
    assignments: { data: AssignmentRow[]; links: PageLink[]; total: number };
    filters: Filters;
    policies: PolicyOption[];
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/attendance-policy-assignments`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (assignment: AssignmentRow) => {
        if (window.confirm(`Archive ${assignment.employee.name}'s ${assignment.policy.name} assignment? The history record will be retained.`)) {
            router.delete(`${baseUrl}/${assignment.id}`);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Employee attendance policy assignments', href: baseUrl }]}>
        <Head title="Employee attendance policy assignments" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Employee attendance policy assignments</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Effective-dated policy history for {company.name}.</p>
                </div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Assign a policy</Link></Button>}
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_16rem_12rem_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search employee or policy<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name, employee code, policy" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Attendance policy<select value={values.attendance_policy_id} onChange={(event) => setValues({ ...values, attendance_policy_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All policies</option>{policies.map((policy) => <option key={policy.id} value={policy.id}>{policy.name} · {policy.code}{policy.deleted ? ' · archived' : policy.status === 'inactive' ? ' · inactive' : ''}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Assignment status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Effective on<input type="date" value={values.effective_date} onChange={(event) => setValues({ ...values, effective_date: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <Button type="submit" variant="outline" className="self-end">Apply</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Attendance policy', 'Effective period', 'Status', 'Period', 'Updated', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{assignments.data.map((assignment) => <tr key={assignment.id} className="border-t">
                        <td className="px-4 py-3"><p className="font-medium">{assignment.employee.name}</p><p className="text-xs text-muted-foreground">{assignment.employee.code}</p></td>
                        <td className="px-4 py-3"><p>{assignment.policy.name}</p><p className="text-xs text-muted-foreground">{assignment.policy.code}</p></td>
                        <td className="whitespace-nowrap px-4 py-3">{displayDate(assignment.effective_from)} → {displayDate(assignment.effective_to)}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{assignment.status}</span></td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{assignment.period}</span></td>
                        <td className="whitespace-nowrap px-4 py-3">{assignment.updated_at ?? '—'}</td>
                        <td className="px-4 py-3"><div className="flex items-center gap-3 whitespace-nowrap">{canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${assignment.id}/edit`}>Edit</Link>}{canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(assignment)}>Archive</button>}</div></td>
                    </tr>)}</tbody>
                </table></div>
                {assignments.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No employee attendance policy assignments match these filters.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{assignments.total} assignments</span><nav className="flex gap-1">{assignments.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
