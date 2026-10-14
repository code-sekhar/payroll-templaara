import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type LeaveType = { id: number; name: string; code: string; description: string | null; category: string; is_paid: boolean; allows_half_day: boolean; status: 'active' | 'inactive'; sort_order: number };
type FormData = { name: string; code: string; description: string; category: string; is_paid: boolean; allows_half_day: boolean; status: 'active' | 'inactive'; sort_order: string };
type Category = { value: string; label: string };

export default function LeaveTypeForm({ leaveType, company, categories, canActivate, canDeactivate }: {
    leaveType: LeaveType | null;
    company: { id: number; name: string };
    categories: Category[];
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const form = useForm<FormData>({
        name: leaveType?.name ?? '', code: leaveType?.code ?? '', description: leaveType?.description ?? '', category: leaveType?.category ?? 'paid',
        is_paid: leaveType?.is_paid ?? true, allows_half_day: leaveType?.allows_half_day ?? false, status: leaveType?.status ?? 'active', sort_order: String(leaveType?.sort_order ?? 0),
    });
    const baseUrl = `/companies/${company.id}/leave-types`;
    const canChangeStatus = leaveType === null ? canDeactivate : leaveType.status === 'active' ? canDeactivate : canActivate;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (leaveType) form.put(`${baseUrl}/${leaveType.id}`);
        else form.post(baseUrl);
    };

    return <AppLayout breadcrumbs={[{ title: 'Leave Types', href: baseUrl }, { title: leaveType ? 'Edit type' : 'Create type', href: leaveType ? `${baseUrl}/${leaveType.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={leaveType ? 'Edit leave type' : 'Create leave type'} />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{leaveType ? 'Edit leave type' : 'Create leave type'}</h1><p className="mt-1 text-sm text-muted-foreground">Define a reusable company leave category for {company.name}. Category and paid status are separate settings.</p></header>
            <form onSubmit={submit} className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                <div className="grid gap-2"><Label htmlFor="name">Name</Label><Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /><InputError message={form.errors.name} /></div>
                <div className="grid gap-2"><Label htmlFor="code">Code</Label><Input id="code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} placeholder="AL" required /><InputError message={form.errors.code} /><p className="text-xs text-muted-foreground">Letters and numbers, optionally separated by hyphens. Stored uppercase.</p></div>
                <div className="grid gap-2"><Label htmlFor="category">Category</Label><select id="category" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>{categories.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}</select><InputError message={form.errors.category} /><p className="text-xs text-muted-foreground">Category groups leave types; it does not set pay treatment automatically.</p></div>
                <div className="grid gap-2"><Label htmlFor="sort_order">Sort order</Label><Input id="sort_order" type="number" min="0" max="65535" step="1" value={form.data.sort_order} onChange={(event) => form.setData('sort_order', event.target.value)} required /><InputError message={form.errors.sort_order} /></div>
                <div className="grid gap-2 sm:col-span-2"><Label htmlFor="description">Description</Label><textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><InputError message={form.errors.description} /></div>
                <label className="flex items-center gap-3 text-sm"><input type="checkbox" checked={form.data.is_paid} onChange={(event) => form.setData('is_paid', event.target.checked)} className="size-4 rounded border-input" />Paid leave type</label>
                <label className="flex items-center gap-3 text-sm"><input type="checkbox" checked={form.data.allows_half_day} onChange={(event) => form.setData('allows_half_day', event.target.checked)} className="size-4 rounded border-input" />Can support half-day leave</label>
                <InputError message={form.errors.is_paid} /><InputError message={form.errors.allows_half_day} />
                <div className="grid gap-2"><Label htmlFor="status">Status</Label><select id="status" disabled={!canChangeStatus} className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as FormData['status'])}><option value="active">Active</option><option value="inactive">Inactive</option></select><InputError message={form.errors.status} /></div>
                <div className="flex items-center gap-3 sm:col-span-2"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save leave type'}</Button><Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link></div>
            </form>
        </main>
    </AppLayout>;
}
