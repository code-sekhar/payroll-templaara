import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Policy = { id: number; name: string; code: string; leave_type_name: string; leave_type_code: string; status: 'active' | 'inactive'; entitlement_days: string; accrual_method: string; accrual_frequency: string; carry_forward_enabled: boolean; max_carry_forward_days: string | null; allows_half_day: boolean; allow_negative_balance: boolean };
type PageLink = { url: string | null; label: string; active: boolean };
type LeaveTypeOption = { id: number; name: string; code: string };
type AccrualMethod = { value: string; label: string };
const days = (value: string | null): string => {
    const [whole = '0', fraction = ''] = String(value ?? '0').split('.');
    return `${whole}.${fraction.padEnd(2, '0').slice(0, 2)}`;
};

export default function LeavePoliciesIndex({ policies, leaveTypes, filters, accrualMethods, company, canCreate, canUpdate, canDelete, canActivate, canDeactivate }: {
    policies: { data: Policy[]; links: PageLink[]; total: number };
    leaveTypes: LeaveTypeOption[];
    filters: { search: string; status: string; accrual_method: string; leave_type_id: string };
    accrualMethods: AccrualMethod[];
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/leave-policies`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (policy: Policy) => {
        if (window.confirm(`Archive ${policy.name}?`)) router.delete(`${baseUrl}/${policy.id}`);
    };

    return <AppLayout breadcrumbs={[{ title: 'Leave Policies', href: baseUrl }]}>
        <Head title="Leave Policies" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4"><div><h1 className="text-2xl font-semibold">Leave Policies</h1><p className="mt-1 text-sm text-muted-foreground">Reusable leave rules for {company.name}. No employee balances or accruals are calculated here.</p></div>{canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create policy</Link></Button>}</header>
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Policy or leave type name/code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Leave type<select value={values.leave_type_id} onChange={(event) => setValues({ ...values, leave_type_id: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All leave types</option>{leaveTypes.map((type) => <option key={type.id} value={type.id}>{type.name} ({type.code})</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Accrual method<select value={values.accrual_method} onChange={(event) => setValues({ ...values, accrual_method: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All methods</option>{accrualMethods.map((method) => <option key={method.value} value={method.value}>{method.label}</option>)}</select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>
            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/50 text-muted-foreground"><tr>{['Policy', 'Leave type', 'Entitlement', 'Accrual', 'Carry forward', 'Half day', 'Negative balance', 'Status', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{policies.data.map((policy) => <tr key={policy.id} className="border-t">
                        <td className="px-4 py-3"><p className="font-medium">{policy.name}</p><p className="text-xs text-muted-foreground">{policy.code}</p></td>
                        <td className="px-4 py-3">{policy.leave_type_name}<p className="text-xs text-muted-foreground">{policy.leave_type_code}</p></td>
                        <td className="px-4 py-3">{days(policy.entitlement_days)} days</td>
                        <td className="px-4 py-3 capitalize">{policy.accrual_method}{policy.accrual_method === 'accrual' ? ` · ${policy.accrual_frequency}` : ''}</td>
                        <td className="px-4 py-3">{policy.carry_forward_enabled ? `${days(policy.max_carry_forward_days)} days max` : 'Disabled'}</td>
                        <td className="px-4 py-3">{policy.allows_half_day ? 'Allowed' : 'No'}</td><td className="px-4 py-3">{policy.allow_negative_balance ? 'Allowed' : 'No'}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{policy.status}</span></td>
                        <td className="px-4 py-3"><div className="flex flex-wrap items-center gap-3 whitespace-nowrap">
                            {canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${policy.id}/edit`}>Edit</Link>}
                            {policy.status === 'active' && canDeactivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${policy.id}/deactivate`)}>Deactivate</button>}
                            {policy.status === 'inactive' && canActivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${policy.id}/activate`)}>Activate</button>}
                            {canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(policy)}>Archive</button>}
                        </div></td>
                    </tr>)}</tbody>
                </table></div>
                {policies.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No leave policies found. Adjust the filters or create a policy.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{policies.total} policies</span><nav className="flex gap-1">{policies.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
