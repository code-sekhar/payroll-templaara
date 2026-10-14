import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string; code?: string };
type Row = { id: number; employee: { id: number; name: string; code: string }; leave_type: Option; leave_policy: Option; period_start: string; period_end: string; balance: string; status: 'open' | 'closed' };
type Filters = { search: string; leave_type_id: string; leave_policy_id: string; status: string; date: string; department_id: string; branch_id: string };
type PageLink = { url: string | null; label: string; active: boolean };
const showDate = (value: string) => new Date(`${value}T00:00:00`).toLocaleDateString();

export default function LeaveBalancesIndex({ accounts, filters, leaveTypes, leavePolicies, departments, branches, company, canCreate }: {
    accounts: { data: Row[]; links: PageLink[]; total: number };
    filters: Filters;
    leaveTypes: Option[];
    leavePolicies: (Option & { leave_type_id: number })[];
    departments: Option[];
    branches: Option[];
    company: { id: number; name: string };
    canCreate: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/leave-balances`;
    const submit = (event: FormEvent) => { event.preventDefault(); router.get(baseUrl, values, { preserveState: true, replace: true }); };

    return <AppLayout breadcrumbs={[{ title: 'Leave balances', href: baseUrl }]}>
        <Head title="Leave balances" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4"><div><h1 className="text-2xl font-semibold">Leave balances</h1><p className="mt-1 text-sm text-muted-foreground">Balances are calculated from the append-only leave ledger.</p></div>{canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create balance account</Link></Button>}</header>
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label className="grid gap-1 text-xs text-muted-foreground">Search employee, type or policy<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Leave type<select value={values.leave_type_id} onChange={(event) => setValues({ ...values, leave_type_id: event.target.value, leave_policy_id: '' })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All leave types</option>{leaveTypes.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Leave policy<select value={values.leave_policy_id} onChange={(event) => setValues({ ...values, leave_policy_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All policies</option>{leavePolicies.filter((policy) => !values.leave_type_id || String(policy.leave_type_id) === values.leave_type_id).map((policy) => <option key={policy.id} value={policy.id}>{policy.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Account status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="open">Open</option><option value="closed">Closed</option></select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Period includes<input type="date" value={values.date} onChange={(event) => setValues({ ...values, date: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Department<select value={values.department_id} onChange={(event) => setValues({ ...values, department_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All departments</option>{departments.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Branch<select value={values.branch_id} onChange={(event) => setValues({ ...values, branch_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All branches</option>{branches.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>
            <section className="overflow-hidden rounded-xl border"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/50 text-muted-foreground"><tr>{['Employee', 'Leave type', 'Leave policy', 'Period', 'Balance', 'Status'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead><tbody>{accounts.data.map((row) => <tr key={row.id} className="border-t"><td className="px-4 py-3"><Link className="font-medium underline-offset-4 hover:underline" href={`${baseUrl}/${row.id}`}>{row.employee.name}</Link><p className="text-xs text-muted-foreground">{row.employee.code}</p></td><td className="px-4 py-3">{row.leave_type.name}<p className="text-xs text-muted-foreground">{row.leave_type.code}</p></td><td className="px-4 py-3">{row.leave_policy.name}<p className="text-xs text-muted-foreground">{row.leave_policy.code}</p></td><td className="whitespace-nowrap px-4 py-3">{showDate(row.period_start)} – {showDate(row.period_end)}</td><td className="whitespace-nowrap px-4 py-3 font-medium">{row.balance} days</td><td className="px-4 py-3 capitalize">{row.status}</td></tr>)}</tbody></table></div>{accounts.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No leave balance accounts match these filters.</p>}<footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{accounts.total} accounts</span><nav className="flex gap-1">{accounts.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer></section>
        </main>
    </AppLayout>;
}
