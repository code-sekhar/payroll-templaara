import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type AssignmentOption = { id: number; employee: string; employee_code: string; leave_type: string; policy: string; effective_from: string; effective_to: string | null };

export default function CreateLeaveBalanceAccount({ company, assignments, search: initialSearch }: { company: { id: number; name: string }; assignments: AssignmentOption[]; search: string }) {
    const [search, setSearch] = useState(initialSearch);
    const baseUrl = `/companies/${company.id}/leave-balances`;
    const form = useForm({ employee_leave_policy_assignment_id: '', period_start: '', period_end: '' });
    const submit = (event: FormEvent) => { event.preventDefault(); form.post(baseUrl); };
    const searchAssignments = (event: FormEvent) => { event.preventDefault(); router.get(`${baseUrl}/create`, { search }, { preserveState: true, replace: true }); };

    return <AppLayout breadcrumbs={[{ title: 'Leave balances', href: baseUrl }, { title: 'Create account', href: `${baseUrl}/create` }]}>
        <Head title="Create leave balance account" />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">Create leave balance account</h1><p className="mt-1 text-sm text-muted-foreground">Ownership is derived from a policy assignment. The balance will start at zero until an opening entry or adjustment is posted.</p></header>
            <form onSubmit={searchAssignments} className="flex gap-2 rounded-xl border p-4"><input aria-label="Search employees" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search employee name or code" className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm" /><Button type="submit" variant="outline">Search</Button></form>
            <form onSubmit={submit} className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2"><Label htmlFor="employee_leave_policy_assignment_id">Employee and leave policy assignment</Label><select id="employee_leave_policy_assignment_id" required value={form.data.employee_leave_policy_assignment_id} onChange={(event) => form.setData('employee_leave_policy_assignment_id', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm"><option value="">Select an assignment</option>{assignments.map((assignment) => <option key={assignment.id} value={assignment.id}>{assignment.employee} · {assignment.employee_code} — {assignment.leave_type} / {assignment.policy} ({assignment.effective_from} to {assignment.effective_to ?? 'open'})</option>)}</select><InputError message={form.errors.employee_leave_policy_assignment_id} /></div>
                <div className="grid gap-2"><Label htmlFor="period_start">Balance period starts</Label><input id="period_start" type="date" required value={form.data.period_start} onChange={(event) => form.setData('period_start', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" /><InputError message={form.errors.period_start} /></div>
                <div className="grid gap-2"><Label htmlFor="period_end">Balance period ends</Label><input id="period_end" type="date" required value={form.data.period_end} onChange={(event) => form.setData('period_end', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" /><InputError message={form.errors.period_end} /></div>
                <p className="text-sm text-muted-foreground sm:col-span-2">The period must fit entirely inside the assignment’s effective dates. Dates are inclusive.</p>
                <div className="flex items-center gap-3 sm:col-span-2"><Button disabled={form.processing || assignments.length === 0}>{form.processing ? 'Creating…' : 'Create account'}</Button><Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link></div>
                {assignments.length === 0 && <p className="text-sm text-muted-foreground sm:col-span-2">No available policy assignments match this search.</p>}
            </form>
        </main>
    </AppLayout>;
}
