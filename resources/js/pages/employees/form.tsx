import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, type ReactNode } from 'react';

type Option = { id: number; name: string; status?: string };
type Employee = Record<string, string | number | null> & { id: number; employee_code: string };
type Choice = { value: string; label: string };

export default function EmployeeForm({ employee, branches, departments, designations, managers, employmentTypes, employmentStatuses }: {
    employee: Employee | null; branches: Option[]; departments: Option[]; designations: Option[]; managers: { id: number; first_name: string; last_name: string; employee_code: string }[]; employmentTypes: Choice[]; employmentStatuses: Choice[];
}) {
    const form = useForm({
        first_name: String(employee?.first_name ?? ''), middle_name: String(employee?.middle_name ?? ''), last_name: String(employee?.last_name ?? ''),
        date_of_birth: String(employee?.date_of_birth ?? '').slice(0, 10), gender: String(employee?.gender ?? ''), marital_status: String(employee?.marital_status ?? ''), personal_email: String(employee?.personal_email ?? ''), personal_phone: String(employee?.personal_phone ?? ''),
        branch_id: employee?.branch_id?.toString() ?? '', department_id: employee?.department_id?.toString() ?? '', designation_id: employee?.designation_id?.toString() ?? '', manager_id: employee?.manager_id?.toString() ?? '',
        joining_date: String(employee?.joining_date ?? '').slice(0, 10), employment_type: String(employee?.employment_type ?? 'full_time'), employment_status: String(employee?.employment_status ?? 'active'),
        address: String(employee?.address ?? ''), city: String(employee?.city ?? ''), state: String(employee?.state ?? ''), country: String(employee?.country ?? ''), postal_code: String(employee?.postal_code ?? ''),
        emergency_contact_name: String(employee?.emergency_contact_name ?? ''), emergency_contact_phone: String(employee?.emergency_contact_phone ?? ''), emergency_contact_relationship: String(employee?.emergency_contact_relationship ?? ''), notes: String(employee?.notes ?? ''),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (employee) {
            form.put(`/employees/${employee.id}`);
        } else {
            form.post('/employees');
        }
    };
    const field = (key: keyof typeof form.data, label: string, required = false, type = 'text') => <div className="grid gap-2"><Label htmlFor={key}>{label}{required ? ' *' : ''}</Label><Input id={key} type={type} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} required={required} /><InputError message={form.errors[key]} /></div>;
    const select = (key: keyof typeof form.data, label: string, options: { value: string | number; label: string }[]) => <div className="grid gap-2"><Label htmlFor={key}>{label}</Label><select id={key} className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)}><option value="">None</option>{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select><InputError message={form.errors[key]} /></div>;
    const section = (title: string, children: ReactNode) => <section className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2"><h2 className="text-lg font-medium sm:col-span-2">{title}</h2>{children}</section>;

    return <AppLayout breadcrumbs={[{ title: 'Employees', href: '/employees' }, { title: employee ? employee.employee_code : 'New employee', href: employee ? `/employees/${employee.id}/edit` : '/employees/create' }]}>
        <Head title={`${employee ? 'Edit' : 'Add'} employee`} />
        <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6"><header><h1 className="text-2xl font-semibold">{employee ? 'Edit employee' : 'Add employee'}</h1><p className="mt-1 text-sm text-muted-foreground">Employee code is generated automatically when saved.</p></header>
            <form onSubmit={submit} className="flex flex-col gap-5">
                {section('Personal information', <>{field('first_name', 'First name', true)}{field('middle_name', 'Middle name')}{field('last_name', 'Last name', true)}{field('date_of_birth', 'Date of birth', false, 'date')}{field('gender', 'Gender')}{field('marital_status', 'Marital status')}{field('personal_email', 'Personal email', false, 'email')}{field('personal_phone', 'Personal phone')}</>)}
                {section('Employment information', <>{field('joining_date', 'Joining date', true, 'date')}{select('employment_type', 'Employment type', employmentTypes)}{select('employment_status', 'Employment status', employmentStatuses)}</>)}
                {section('Organization', <>{select('branch_id', 'Branch', branches.map((item) => ({ value: item.id, label: `${item.name}${item.status === 'inactive' ? ' (inactive)' : ''}` })))}{select('department_id', 'Department', departments.map((item) => ({ value: item.id, label: `${item.name}${item.status === 'inactive' ? ' (inactive)' : ''}` })))}{select('designation_id', 'Designation', designations.map((item) => ({ value: item.id, label: `${item.name}${item.status === 'inactive' ? ' (inactive)' : ''}` })))}{select('manager_id', 'Manager', managers.map((item) => ({ value: item.id, label: `${item.first_name} ${item.last_name} (${item.employee_code})` })))}</>)}
                {section('Contact information', <>{field('address', 'Address')}{field('city', 'City')}{field('state', 'State')}{field('country', 'Country code')}{field('postal_code', 'Postal code')}</>)}
                {section('Emergency contact', <>{field('emergency_contact_name', 'Contact name')}{field('emergency_contact_phone', 'Contact phone')}{field('emergency_contact_relationship', 'Relationship')}{field('notes', 'Notes')}</>)}
                <div className="flex gap-3"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save employee'}</Button><Link href="/employees" className="self-center text-sm underline underline-offset-4">Cancel</Link></div>
            </form>
        </main>
    </AppLayout>;
}
