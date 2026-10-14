import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface CompanyInfo {
    id: number; name: string; legal_name: string | null; slug: string; email: string | null;
    phone: string | null; address: string | null; city: string | null; state: string | null;
    country: string | null; postal_code: string | null; timezone: string; currency: string; status: string;
}

export default function CompanyShow({ company, can, isActive }: { company: CompanyInfo; can: { updateCompany: boolean; viewUsers: boolean; viewRoles: boolean }; isActive: boolean }) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Companies', href: '/companies' }, { title: company.name, href: `/companies/${company.id}` }];
    const form = useForm({ name: company.name, legal_name: company.legal_name ?? '', slug: company.slug, email: company.email ?? '', phone: company.phone ?? '', address: company.address ?? '', city: company.city ?? '', state: company.state ?? '', country: company.country ?? '', postal_code: company.postal_code ?? '', timezone: company.timezone, currency: company.currency });
    const submit = (event: FormEvent) => { event.preventDefault(); form.put(`/companies/${company.id}`); };
    const fields: { name: keyof typeof form.data; label: string }[] = [
        { name: 'name', label: 'Company name' }, { name: 'legal_name', label: 'Legal name' }, { name: 'slug', label: 'Slug' },
        { name: 'email', label: 'Email' }, { name: 'phone', label: 'Phone' }, { name: 'address', label: 'Address' },
        { name: 'city', label: 'City' }, { name: 'state', label: 'State' }, { name: 'country', label: 'Country code' },
        { name: 'postal_code', label: 'Postal code' }, { name: 'timezone', label: 'Timezone' }, { name: 'currency', label: 'Currency' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={company.name} />
            <section className="flex flex-col gap-6 p-4">
                <header><h1 className="text-2xl font-semibold">{company.name}</h1><p className="text-muted-foreground mt-1 text-sm">{isActive ? 'Active company' : 'Company information'} · {company.status}</p></header>
                <nav className="flex flex-wrap gap-3 text-sm">
                    {can.viewUsers && <Link className="underline underline-offset-4" href={`/companies/${company.id}/members`}>Company members</Link>}
                    {can.viewRoles && <Link className="underline underline-offset-4" href={`/companies/${company.id}/roles`}>Roles and permissions</Link>}
                </nav>
                {can.updateCompany ? <form onSubmit={submit} className="grid max-w-4xl gap-5 rounded-xl border p-5 sm:grid-cols-2">
                    {fields.map(({ name, label }) => <label key={name} className="flex flex-col gap-2 text-sm font-medium">{label}<input className="rounded-md border border-input bg-background px-3 py-2 font-normal" value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} /><InputError message={form.errors[name]} /></label>)}
                    <div className="sm:col-span-2"><button disabled={form.processing} className="rounded-md bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50">Save company</button></div>
                </form> : <dl className="grid max-w-4xl gap-4 rounded-xl border p-5 sm:grid-cols-2">{fields.map(({ name, label }) => <div key={name}><dt className="text-muted-foreground text-sm">{label}</dt><dd className="mt-1">{company[name] || '—'}</dd></div>)}</dl>}
            </section>
        </AppLayout>
    );
}
