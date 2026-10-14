import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Policy = {
    id: number;
    name: string;
    code: string;
    status: 'active' | 'inactive';
    grace_in_minutes: number;
    grace_out_minutes: number;
    overtime_enabled: boolean;
    overtime_after_minutes: number;
    updated_at: string | null;
};
type PageLink = { url: string | null; label: string; active: boolean };

export default function AttendancePoliciesIndex({ policies, filters, company, worksitesUrl, canCreate, canUpdate, canDelete, canActivate, canDeactivate }: {
    policies: { data: Policy[]; links: PageLink[]; total: number };
    filters: { search: string; status: string };
    company: { id: number; name: string };
    worksitesUrl: string;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const baseUrl = `/companies/${company.id}/attendance-policies`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, { search, status }, { preserveState: true, replace: true });
    };
    const remove = (policy: Policy) => {
        if (window.confirm(`Archive ${policy.name}?`)) router.delete(`${baseUrl}/${policy.id}`);
    };

    return <AppLayout breadcrumbs={[{ title: 'Attendance policies', href: baseUrl }]}>
        <Head title="Attendance policies" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Attendance policies</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Reusable attendance rule configurations for {company.name}.</p>
                </div>
                <div className="flex flex-wrap gap-2"><Button variant="outline" asChild><Link href={worksitesUrl}>Manage worksites</Link></Button>{canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create policy</Link></Button>}</div>
            </header>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Policy name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={status} onChange={(event) => setStatus(event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr>
                            {['Name', 'Code', 'Status', 'Grace In', 'Grace Out', 'Overtime', 'Updated', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}
                        </tr></thead>
                        <tbody>{policies.data.map((policy) => <tr key={policy.id} className="border-t">
                            <td className="px-4 py-3 font-medium">{policy.name}</td>
                            <td className="px-4 py-3">{policy.code}</td>
                            <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{policy.status}</span></td>
                            <td className="px-4 py-3">{policy.grace_in_minutes} min</td>
                            <td className="px-4 py-3">{policy.grace_out_minutes} min</td>
                            <td className="px-4 py-3">{policy.overtime_enabled ? `After ${policy.overtime_after_minutes} min` : 'Disabled'}</td>
                            <td className="whitespace-nowrap px-4 py-3">{policy.updated_at ?? '—'}</td>
                            <td className="px-4 py-3"><div className="flex flex-wrap items-center gap-3 whitespace-nowrap">
                                {canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${policy.id}/edit`}>Edit</Link>}
                                {policy.status === 'active' && canDeactivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${policy.id}/deactivate`)}>Deactivate</button>}
                                {policy.status === 'inactive' && canActivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${policy.id}/activate`)}>Activate</button>}
                                {canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => remove(policy)}>Archive</button>}
                            </div></td>
                        </tr>)}</tbody>
                    </table>
                </div>
                {policies.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No attendance policies found. Adjust the filters or create a policy.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground">
                    <span>{policies.total} policies</span>
                    <nav className="flex gap-1">{policies.links.map((link, index) => link.url
                        ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
                </footer>
            </section>
        </main>
    </AppLayout>;
}
