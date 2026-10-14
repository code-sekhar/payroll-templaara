import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type HolidayCalendarFormData = { id: number; name: string; year: number; description: string | null; status: 'active' | 'inactive' };

export default function HolidayCalendarForm({ calendar, company }: { calendar: HolidayCalendarFormData | null; company: { id: number; name: string } }) {
    const form = useForm({ name: calendar?.name ?? '', year: String(calendar?.year ?? new Date().getFullYear()), description: calendar?.description ?? '' });
    const baseUrl = `/companies/${company.id}/holiday-calendars`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (calendar) {
            form.put(`${baseUrl}/${calendar.id}`);
        } else {
            form.post(baseUrl);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Holiday calendars', href: baseUrl }, { title: calendar ? 'Edit calendar' : 'Create calendar', href: calendar ? `${baseUrl}/${calendar.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={calendar ? 'Edit holiday calendar' : 'Create holiday calendar'} />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{calendar ? 'Edit holiday calendar' : 'Create holiday calendar'}</h1><p className="mt-1 text-sm text-muted-foreground">Calendar dates are local date values for {company.name}.</p></header>
            <form onSubmit={submit} className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                <div className="grid gap-2"><Label htmlFor="name">Calendar name</Label><Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} maxLength={255} required /><InputError message={form.errors.name} /></div>
                <div className="grid gap-2"><Label htmlFor="year">Calendar year</Label><Input id="year" type="number" min="1900" max="2200" value={form.data.year} onChange={(event) => form.setData('year', event.target.value)} required /><InputError message={form.errors.year} /></div>
                <div className="grid gap-2 sm:col-span-2"><Label htmlFor="description">Description</Label><textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><InputError message={form.errors.description} /></div>
                {calendar && <p className="text-sm text-muted-foreground sm:col-span-2">Status: <span className="font-medium capitalize text-foreground">{calendar.status}</span>. Change status from the calendar detail page.</p>}
                <div className="flex items-center gap-3 sm:col-span-2"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save calendar'}</Button><Link href={calendar ? `${baseUrl}/${calendar.id}` : baseUrl} className="text-sm underline underline-offset-4">Cancel</Link></div>
            </form>
        </main>
    </AppLayout>;
}
