import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

interface Member { id: number; name: string; email: string; role: string | null }
interface CompanySummary { id: number; name: string }

function AddMemberForm({ companyId }: { companyId: number }) {
    const form = useForm({ email: '', role: 'employee' });
    return <form className="grid gap-4 rounded-xl border p-5 sm:grid-cols-[1fr_12rem_auto] sm:items-end" onSubmit={(event) => { event.preventDefault(); form.post(`/companies/${companyId}/members`, { onSuccess: () => form.reset() }); }}>
        <label className="flex flex-col gap-2 text-sm font-medium">Verified account email<input type="email" required autoComplete="off" className="rounded-md border border-input bg-background px-3 py-2 font-normal" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /><InputError message={form.errors.email} /></label>
        <label className="flex flex-col gap-2 text-sm font-medium">Company role<select className="rounded-md border border-input bg-background px-3 py-2 font-normal" value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>{['hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'].map((role) => <option key={role} value={role}>{role.replace('_', ' ')}</option>)}</select><InputError message={form.errors.role} /></label>
        <button disabled={form.processing} className="rounded-md bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50">Add member</button>
    </form>;
}

function MemberRole({ companyId, member }: { companyId: number; member: Member }) {
    const form = useForm({ role: member.role ?? 'employee' });
    return <form className="flex items-center gap-2" onSubmit={(event) => { event.preventDefault(); form.patch(`/companies/${companyId}/members/${member.id}`); }}>
        <select aria-label={`Role for ${member.name}`} className="rounded-md border bg-background px-2 py-1.5" value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
            {['hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'].map((role) => <option key={role} value={role}>{role.replace('_', ' ')}</option>)}
        </select>
        <button disabled={form.processing} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">Save</button>
        <InputError message={form.errors.role} />
    </form>;
}

export default function CompanyMembers({ company, members, canManageUsers, canAddUsers }: { company: CompanySummary; members: Member[]; canManageUsers: boolean; canAddUsers: boolean }) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Companies', href: '/companies' }, { title: company.name, href: `/companies/${company.id}` }, { title: 'Members', href: `/companies/${company.id}/members` }];
    return <AppLayout breadcrumbs={breadcrumbs}>
        <Head title={`${company.name} members`} />
        <section className="flex flex-col gap-6 p-4">
            <header><h1 className="text-2xl font-semibold">Company members</h1><p className="text-muted-foreground mt-1 text-sm">People and their roles in {company.name}.</p></header>
            {canAddUsers && <AddMemberForm companyId={company.id} />}
            <div className="overflow-x-auto rounded-xl border"><table className="w-full text-left text-sm"><thead className="bg-muted/50"><tr><th className="p-3 font-medium">Name</th><th className="p-3 font-medium">Email</th><th className="p-3 font-medium">Company role</th></tr></thead><tbody>
                {members.map((member) => <tr key={member.id} className="border-t"><td className="p-3 font-medium">{member.name}</td><td className="p-3">{member.email}</td><td className="p-3">{canManageUsers && member.role !== 'company_owner' ? <MemberRole companyId={company.id} member={member} /> : (member.role?.replace('_', ' ') ?? '—')}</td></tr>)}
                {!members.length && <tr><td colSpan={3} className="text-muted-foreground p-4">No active members.</td></tr>}
            </tbody></table></div>
        </section>
    </AppLayout>;
}
