import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type HolidayFormData = { id: number; date: string; name: string; description: string | null; type: string; status: 'active' | 'inactive'; is_optional: boolean };

export default function HolidayForm({ holiday, calendar, company, holidayTypes, holidayStatuses }: {
    holiday: HolidayFormData | null;
    calendar: { id: number; name: string; year: number; status: 'active' | 'inactive' };
    company: { id: number; name: string };
    holidayTypes: { value: string; label: string }[];
    holidayStatuses: { value: 'active' | 'inactive'; label: string }[];
}) {
    const form = useForm({
        date: holiday?.date ?? `${calendar.year}-01-01`,
        name: holiday?.name ?? '',
        description: holiday?.description ?? '',
        type: holiday?.type ?? 'public',
        status: holiday?.status ?? 'active',
        is_optional: holiday?.is_optional ?? false,
    });
    const calendarUrl = `/companies/${company.id}/holiday-calendars/${calendar.id}`;
    const baseUrl = `${calendarUrl}/holidays`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (holiday) {
            form.put(`${baseUrl}/${holiday.id}`);
        } else {
            form.post(baseUrl);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Holiday calendars', href: `/companies/${company.id}/holiday-calendars` }, { title: calendar.name, href: calendarUrl }, { title: holiday ? 'Edit holiday' : 'Add holiday', href: holiday ? `${baseUrl}/${holiday.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={holiday ? 'Edit holiday' : 'Add holiday'} />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{holiday ? 'Edit holiday' : 'Add holiday'}</h1><p className="mt-1 text-sm text-muted-foreground">{calendar.name} · {calendar.year}. Holiday dates are local calendar dates.</p></header>
            <form onSubmit={submit} className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                <div className="grid gap-2"><Label htmlFor="name">Holiday name</Label><Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} maxLength={255} required /><InputError message={form.errors.name} /></div>
                <div className="grid gap-2"><Label htmlFor="date">Date</Label><Input id="date" type="date" min={`${calendar.year}-01-01`} max={`${calendar.year}-12-31`} value={form.data.date} onChange={(event) => form.setData('date', event.target.value)} required /><InputError message={form.errors.date} /></div>
                <div className="grid gap-2"><Label htmlFor="type">Type</Label><select id="type" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>{holidayTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select><InputError message={form.errors.type} /></div>
                <div className="grid gap-2"><Label htmlFor="status">Status</Label><select id="status" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as 'active' | 'inactive')}>{holidayStatuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}</select><InputError message={form.errors.status} /></div>
                <label className="flex items-center gap-3 rounded-md border p-3 sm:col-span-2"><input type="checkbox" checked={form.data.is_optional} onChange={(event) => form.setData('is_optional', event.target.checked)} className="size-4 rounded border-input" /><span><span className="block text-sm font-medium">Optional holiday</span><span className="text-xs text-muted-foreground">Employee-specific selection is not part of this phase.</span></span></label>
                <InputError message={form.errors.is_optional} />
                <div className="grid gap-2 sm:col-span-2"><Label htmlFor="description">Description</Label><textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><InputError message={form.errors.description} /></div>
                <div className="flex items-center gap-3 sm:col-span-2"><Button disabled={form.processing || (!holiday && calendar.status !== 'active')}>{form.processing ? 'Saving…' : 'Save holiday'}</Button><Link href={calendarUrl} className="text-sm underline underline-offset-4">Cancel</Link>{!holiday && calendar.status !== 'active' && <p className="text-sm text-muted-foreground">Activate this calendar before adding holidays.</p>}</div>
            </form>
        </main>
    </AppLayout>;
}
