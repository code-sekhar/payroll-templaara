import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type EmployeeOption = { id: number; employee_code: string; name: string };
type PolicyOption = { id: number; name: string; code: string; status: 'active' | 'inactive'; deleted: boolean };
type Assignment = {
    id: number;
    employee_id: number;
    employee_name: string;
    employee_code: string;
    attendance_policy_id: number;
    policy_name: string;
    policy_code: string;
    effective_from: string;
    effective_to: string | null;
    status: 'active' | 'inactive';
    notes: string | null;
};

export default function EmployeeAttendancePolicyAssignmentForm({ assignment, company, employees, policies, employeeSearch: initialEmployeeSearch }: {
    assignment: Assignment | null;
    company: { id: number; name: string };
    employees: EmployeeOption[];
    policies: PolicyOption[];
    employeeSearch: string;
}) {
    const form = useForm({
        employee_id: assignment ? String(assignment.employee_id) : '',
        attendance_policy_id: assignment ? String(assignment.attendance_policy_id) : '',
        effective_from: assignment?.effective_from ?? '',
        effective_to: assignment?.effective_to ?? '',
        status: assignment?.status ?? 'active' as 'active' | 'inactive',
        notes: assignment?.notes ?? '',
    });
    const [employeeSearch, setEmployeeSearch] = useState(initialEmployeeSearch);
    const baseUrl = `/companies/${company.id}/attendance-policy-assignments`;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (assignment) form.put(`${baseUrl}/${assignment.id}`);
        else form.post(baseUrl);
    };

    const searchEmployees = () => router.get(`${baseUrl}/${assignment ? `${assignment.id}/edit` : 'create'}`, { employeeSearch }, { preserveState: true, replace: true });

    return <AppLayout breadcrumbs={[{ title: 'Employee attendance policy assignments', href: baseUrl }, { title: assignment ? 'Edit assignment' : 'Assign a policy', href: assignment ? `${baseUrl}/${assignment.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={assignment ? 'Edit attendance policy assignment' : 'Assign an attendance policy'} />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 className="text-2xl font-semibold">{assignment ? 'Edit attendance policy assignment' : 'Assign an attendance policy'}</h1>
                <p className="mt-1 text-sm text-muted-foreground">Set inclusive effective dates for an employee at {company.name}. Leave the end date blank for an open-ended assignment.</p>
            </header>

            <form onSubmit={submit} className="grid gap-6 rounded-xl border p-5 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="employeeSearch">Find an employee</Label>
                    <div className="flex gap-2">
                        <input id="employeeSearch" value={employeeSearch} onChange={(event) => setEmployeeSearch(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); searchEmployees(); } }} placeholder="Name or employee code" className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm" />
                        <Button type="button" variant="outline" onClick={searchEmployees}>Search</Button>
                    </div>
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="employee_id">Employee</Label>
                    <select id="employee_id" required className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.employee_id} onChange={(event) => form.setData('employee_id', event.target.value)}>
                        <option value="">Select employee</option>
                        {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name} · {employee.employee_code}</option>)}
                    </select>
                    <InputError message={form.errors.employee_id} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="attendance_policy_id">Attendance policy</Label>
                    <select id="attendance_policy_id" required className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.attendance_policy_id} onChange={(event) => form.setData('attendance_policy_id', event.target.value)}>
                        <option value="">Select policy</option>
                        {policies.map((policy) => <option key={policy.id} value={policy.id}>{policy.name} · {policy.code}{policy.deleted ? ' · archived' : policy.status === 'inactive' ? ' · inactive' : ''}</option>)}
                    </select>
                    <InputError message={form.errors.attendance_policy_id} />
                    {policies.length === 0 && <p className="text-sm text-muted-foreground">No active attendance policies are available. Create or activate a policy first.</p>}
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="effective_from">Effective from</Label>
                    <input id="effective_from" type="date" required className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.effective_from} onChange={(event) => form.setData('effective_from', event.target.value)} />
                    <InputError message={form.errors.effective_from} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="effective_to">Effective to (optional)</Label>
                    <input id="effective_to" type="date" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.effective_to} onChange={(event) => form.setData('effective_to', event.target.value)} />
                    <InputError message={form.errors.effective_to} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="status">Assignment status</Label>
                    <select id="status" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as 'active' | 'inactive')}>
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                    <InputError message={form.errors.status} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="notes">Notes</Label>
                    <textarea id="notes" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />
                    <InputError message={form.errors.notes} />
                </div>
                <div className="flex items-center gap-3 sm:col-span-2">
                    <Button disabled={form.processing || employees.length === 0 || policies.length === 0}>{form.processing ? 'Saving…' : 'Save assignment'}</Button>
                    <Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link>
                </div>
                {employees.length === 0 && <p className="text-sm text-muted-foreground sm:col-span-2">No employees were found. Search above or add an employee first.</p>}
            </form>
        </main>
    </AppLayout>;
}
