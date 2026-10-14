import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Organization = { id: number; name: string; code: string; email?: string | null; phone?: string | null; address?: string | null; city?: string | null; state?: string | null; country?: string | null; postal_code?: string | null; timezone?: string; description?: string | null; level?: number | null; status: string };

export default function OrganizationForm({ type, title, record }: { type: 'branches' | 'departments' | 'designations'; title: string; record: Organization | null }) {
    const form = useForm({
        name: record?.name ?? '', code: record?.code ?? '', email: record?.email ?? '', phone: record?.phone ?? '',
        address: record?.address ?? '', city: record?.city ?? '', state: record?.state ?? '', country: record?.country ?? '',
        postal_code: record?.postal_code ?? '', timezone: record?.timezone ?? 'UTC', description: record?.description ?? '',
        level: record?.level?.toString() ?? '', status: record?.status ?? 'active',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (record) {
            form.put(`/${type}/${record.id}`);
        } else {
            form.post(`/${type}`);
        }
    };
    const field = (name: keyof typeof form.data, label: string, required = false, kind = 'text') => <div className="grid gap-2">
        <Label htmlFor={name}>{label}{required ? ' *' : ''}</Label><Input id={name} type={kind} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} required={required} />
        <InputError message={form.errors[name]} />
    </div>;

    return <AppLayout breadcrumbs={[{ title: `${title}s`, href: `/${type}` }, { title: record ? 'Edit' : 'Create', href: record ? `/${type}/${record.id}` : `/${type}/create` }]}>
        <Head title={`${record ? 'Edit' : 'Add'} ${title}`} />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{record ? 'Edit' : 'Add'} {title}</h1><p className="mt-1 text-sm text-muted-foreground">This record belongs to the active company.</p></header>
            <form onSubmit={submit} className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                {field('name', 'Name', true)}{field('code', 'Code', true)}
                {type === 'branches' ? <>{field('email', 'Email', false, 'email')}{field('phone', 'Phone')}{field('address', 'Address')}{field('city', 'City')}{field('state', 'State')}{field('country', 'Country code')}{field('postal_code', 'Postal code')}{field('timezone', 'Timezone', true)}</> : <>{field('description', 'Description')}{type === 'designations' && field('level', 'Level', false, 'number')}</>}
                <div className="grid gap-2"><Label htmlFor="status">Status</Label><select id="status" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></select><InputError message={form.errors.status} /></div>
                <div className="flex items-center gap-3 sm:col-span-2"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save'}</Button><Link href={`/${type}`} className="text-sm underline underline-offset-4">Cancel</Link></div>
            </form>
        </main>
    </AppLayout>;
}
