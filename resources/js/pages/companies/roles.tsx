import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Permission { id: number; name: string }
interface CompanyRole { id: number; name: string; permissions: Permission[] }
interface CompanySummary { id: number; name: string }

export default function CompanyRoles({ company, roles }: { company: CompanySummary; roles: CompanyRole[] }) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Companies', href: '/companies' }, { title: company.name, href: `/companies/${company.id}` }, { title: 'Roles', href: `/companies/${company.id}/roles` }];
    return <AppLayout breadcrumbs={breadcrumbs}>
        <Head title={`${company.name} roles`} />
        <section className="flex flex-col gap-6 p-4">
            <header><h1 className="text-2xl font-semibold">Roles and permissions</h1><p className="text-muted-foreground mt-1 text-sm">Permission assignments for {company.name}.</p></header>
            <div className="grid gap-4 lg:grid-cols-2">{roles.map((role) => <article key={role.id} className="rounded-xl border p-5">
                <h2 className="text-lg font-semibold capitalize">{role.name.replace('_', ' ')}</h2>
                <ul className="text-muted-foreground mt-4 grid gap-2 text-sm sm:grid-cols-2">{role.permissions.map((permission) => <li key={permission.id}>{permission.name}</li>)}</ul>
            </article>)}</div>
        </section>
    </AppLayout>;
}
