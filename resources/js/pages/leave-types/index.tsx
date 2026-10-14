import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type LeaveType = { id: number; name: string; code: string; category: string; is_paid: boolean; allows_half_day: boolean; status: 'active' | 'inactive'; policies_count: number };
type PageLink = { url: string | null; label: string; active: boolean };
type Category = { value: string; label: string };

export default function LeaveTypesIndex({ leaveTypes, filters, categories, company, canCreate, canUpdate, canDelete, canActivate, canDeactivate, errors }: {
    leaveTypes: { data: LeaveType[]; links: PageLink[]; total: number };
    filters: { search: string; category: string; status: string };
    categories: Category[];
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
    errors?: Record<string, string>;
}) {
    const [values, setValues] = useState(filters);
    const baseUrl = `/companies/${company.id}/leave-types`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(baseUrl, values, { preserveState: true, replace: true });
    };
    const archive = (leaveType: LeaveType) => {
        if (window.confirm(`Archive ${leaveType.name}? Active policies must be removed or deactivated first.`)) {
            router.delete(`${baseUrl}/${leaveType.id}`);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Leave Types', href: baseUrl }]}>
        <Head title="Leave Types" />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold">Leave Types</h1><p className="mt-1 text-sm text-muted-foreground">Company-defined leave categories for {company.name}.</p></div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Create leave type</Link></Button>}
            </header>

            {errors?.leave_type && <p role="alert" className="rounded-lg border border-destructive/40 p-3 text-sm text-destructive">{errors.leave_type}</p>}
            <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto]">
                <label className="grid gap-1 text-xs text-muted-foreground">Search<input value={values.search} onChange={(event) => setValues({ ...values, search: event.target.value })} placeholder="Name or code" className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground" /></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Category<select value={values.category} onChange={(event) => setValues({ ...values, category: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All categories</option>{categories.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}</select></label>
                <label className="grid gap-1 text-xs text-muted-foreground">Status<select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })} className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <Button type="submit" variant="outline" className="self-end">Apply filters</Button>
            </form>

            <section className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr>{['Name', 'Code', 'Category', 'Paid', 'Half day', 'Status', 'Policies', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                    <tbody>{leaveTypes.data.map((leaveType) => <tr key={leaveType.id} className="border-t">
                        <td className="px-4 py-3 font-medium">{leaveType.name}</td><td className="px-4 py-3">{leaveType.code}</td><td className="px-4 py-3 capitalize">{leaveType.category.replace('_', ' ')}</td>
                        <td className="px-4 py-3">{leaveType.is_paid ? 'Paid' : 'Unpaid'}</td><td className="px-4 py-3">{leaveType.allows_half_day ? 'Allowed' : 'No'}</td>
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{leaveType.status}</span></td><td className="px-4 py-3">{leaveType.policies_count}</td>
                        <td className="px-4 py-3"><div className="flex flex-wrap items-center gap-3 whitespace-nowrap">
                            {canUpdate && <Link className="underline underline-offset-4" href={`${baseUrl}/${leaveType.id}/edit`}>Edit</Link>}
                            {leaveType.status === 'active' && canDeactivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${leaveType.id}/deactivate`)}>Deactivate</button>}
                            {leaveType.status === 'inactive' && canActivate && <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => router.patch(`${baseUrl}/${leaveType.id}/activate`)}>Activate</button>}
                            {canDelete && <button type="button" className="text-destructive hover:underline" onClick={() => archive(leaveType)}>Archive</button>}
                        </div></td>
                    </tr>)}</tbody>
                </table></div>
                {leaveTypes.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No leave types found. Adjust the filters or create a leave type.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"><span>{leaveTypes.total} leave types</span><nav className="flex gap-1">{leaveTypes.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></footer>
            </section>
        </main>
    </AppLayout>;
}
