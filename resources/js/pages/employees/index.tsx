import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string; status?: string };
type Choice = { value: string; label: string };
type EmployeeRow = { id: number; employee_code: string; name: string; department: string | null; designation: string | null; branch: string | null; employment_status: string; employment_type: string };
type LinkItem = { url: string | null; label: string; active: boolean };

export default function EmployeesIndex({ employees, filters, branches, departments, designations, employmentTypes, employmentStatuses, canCreate }: {
    employees: { data: EmployeeRow[]; links: LinkItem[]; total: number }; filters: Record<string, string>;
    branches: Option[]; departments: Option[]; designations: Option[]; employmentTypes: Choice[]; employmentStatuses: Choice[]; canCreate: boolean;
}) {
    const [values, setValues] = useState({ search: filters.search ?? '', department_id: filters.department_id ?? '', designation_id: filters.designation_id ?? '', branch_id: filters.branch_id ?? '', employment_status: filters.employment_status ?? '', employment_type: filters.employment_type ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); router.get('/employees', values, { preserveState: true, replace: true }); };
    const filter = (name: keyof typeof values, label: string, options: (Option | Choice)[]) => <label className="grid gap-1 text-xs text-muted-foreground" key={name}>{label}<select className="h-10 rounded-md border border-input bg-background px-2 text-sm text-foreground" value={values[name]} onChange={(event) => setValues({ ...values, [name]: event.target.value })}><option value="">All</option>{options.map((option) => <option key={'id' in option ? option.id : option.value} value={'id' in option ? option.id : option.value}>{'name' in option ? option.name : option.label}</option>)}</select></label>;
    return <AppLayout breadcrumbs={[{ title: 'Employees', href: '/employees' }]}>
        <Head title="Employees" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4"><div><h1 className="text-2xl font-semibold">Employees</h1><p className="mt-1 text-sm text-muted-foreground">People and work details for the active company.</p></div>{canCreate && <Link href="/employees/create" className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Add employee</Link>}</header>
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label className="grid gap-1 text-xs text-muted-foreground sm:col-span-2">Search<input className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" placeholder="Name or employee code" value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} /></label>
                {filter('department_id', 'Department', departments)}{filter('designation_id', 'Designation', designations)}{filter('branch_id', 'Branch', branches)}{filter('employment_status', 'Status', employmentStatuses)}{filter('employment_type', 'Employment type', employmentTypes)}
                <div className="flex items-end"><button className="h-10 rounded-md border px-4 text-sm">Apply filters</button></div>
            </form>
            <div className="overflow-hidden rounded-xl border"><div className="overflow-x-auto"><table className="w-full text-left text-sm">
                <thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Department', 'Designation', 'Branch', 'Employment', 'Status'].map((label) => <th className="px-4 py-3 font-medium" key={label}>{label}</th>)}</tr></thead>
                <tbody>{employees.data.map((employee) => <tr key={employee.id} className="border-t hover:bg-muted/30"><td className="px-4 py-3"><Link href={`/employees/${employee.id}`} className="font-medium underline-offset-4 hover:underline">{employee.name}</Link><p className="text-xs text-muted-foreground">{employee.employee_code}</p></td><td className="px-4 py-3">{employee.department ?? '—'}</td><td className="px-4 py-3">{employee.designation ?? '—'}</td><td className="px-4 py-3">{employee.branch ?? '—'}</td><td className="px-4 py-3 capitalize">{employee.employment_type.replaceAll('_', ' ')}</td><td className="px-4 py-3 capitalize">{employee.employment_status.replaceAll('_', ' ')}</td></tr>)}</tbody>
            </table></div>
                {employees.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No employees match these filters.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{employees.total} employees</span><nav className="flex gap-1">{employees.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </div>
        </main>
    </AppLayout>;
}
