import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type ShiftFormData = {
    id: number;
    name: string;
    code: string;
    start_time: string;
    end_time: string;
    break_duration_minutes: number;
    grace_period_minutes: number;
    is_overnight: boolean;
    duration_minutes: number;
    net_duration_minutes: number;
    status: 'active' | 'inactive';
    description: string | null;
};

const minutesFromClock = (time: string): number | null => {
    const [hours, minutes] = time.split(':').map(Number);
    return time && Number.isFinite(hours) && Number.isFinite(minutes) ? hours * 60 + minutes : null;
};

const displayDuration = (minutes: number): string => `${Math.floor(minutes / 60)} hours${minutes % 60 ? ` ${minutes % 60} minutes` : ''}`;

export default function ShiftForm({ shift, company, canActivate, canDeactivate }: { shift: ShiftFormData | null; company: { id: number; name: string; timezone: string }; canActivate: boolean; canDeactivate: boolean }) {
    const form = useForm({
        name: shift?.name ?? '',
        code: shift?.code ?? '',
        start_time: shift?.start_time ?? '09:00',
        end_time: shift?.end_time ?? '18:00',
        break_duration_minutes: String(shift?.break_duration_minutes ?? 0),
        grace_period_minutes: String(shift?.grace_period_minutes ?? 0),
        status: shift?.status ?? 'active',
        description: shift?.description ?? '',
    });
    const baseUrl = `/companies/${company.id}/shifts`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (shift) {
            form.put(`${baseUrl}/${shift.id}`);
        } else {
            form.post(baseUrl);
        }
    };

    const startMinutes = minutesFromClock(form.data.start_time);
    const endMinutes = minutesFromClock(form.data.end_time);
    const sameTime = startMinutes !== null && startMinutes === endMinutes;
    const durationMinutes = startMinutes !== null && endMinutes !== null && !sameTime
        ? (endMinutes - startMinutes + 1440) % 1440
        : null;
    const isOvernight = startMinutes !== null && endMinutes !== null && endMinutes < startMinutes;
    const breakMinutes = Number(form.data.break_duration_minutes) || 0;
    const canChangeStatus = shift === null || (shift.status === 'active' ? canDeactivate : canActivate);

    const field = (name: 'name' | 'code', label: string, required = true) => <div className="grid gap-2">
        <Label htmlFor={name}>{label}</Label>
        <Input id={name} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} required={required} />
        <InputError message={form.errors[name]} />
    </div>;

    return <AppLayout breadcrumbs={[{ title: 'Shifts', href: baseUrl }, { title: shift ? 'Edit shift' : 'Create shift', href: shift ? `${baseUrl}/${shift.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={shift ? 'Edit shift' : 'Create shift'} />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 className="text-2xl font-semibold">{shift ? 'Edit shift' : 'Create shift'}</h1>
                <p className="mt-1 text-sm text-muted-foreground">Shift times are local to {company.name} ({company.timezone}) and are stored as times of day.</p>
            </header>

            <form onSubmit={submit} className="grid gap-6 rounded-xl border p-5 sm:grid-cols-2">
                {field('name', 'Shift name')}
                {field('code', 'Shift code')}
                <div className="grid gap-2">
                    <Label htmlFor="start_time">Start time</Label>
                    <Input id="start_time" type="time" step="60" value={form.data.start_time} onChange={(event) => form.setData('start_time', event.target.value)} required />
                    <InputError message={form.errors.start_time} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="end_time">End time</Label>
                    <Input id="end_time" type="time" step="60" value={form.data.end_time} onChange={(event) => form.setData('end_time', event.target.value)} required />
                    <InputError message={form.errors.end_time} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="break_duration_minutes">Break duration (minutes)</Label>
                    <Input id="break_duration_minutes" type="number" min="0" max="65535" value={form.data.break_duration_minutes} onChange={(event) => form.setData('break_duration_minutes', event.target.value)} required />
                    <InputError message={form.errors.break_duration_minutes} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="grace_period_minutes">Grace period (minutes)</Label>
                    <Input id="grace_period_minutes" type="number" min="0" max="2147483647" value={form.data.grace_period_minutes} onChange={(event) => form.setData('grace_period_minutes', event.target.value)} required />
                    <InputError message={form.errors.grace_period_minutes} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="status">Status</Label>
                    <select id="status" disabled={!canChangeStatus} className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as 'active' | 'inactive')}>
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                    <InputError message={form.errors.status} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="description">Description</Label>
                    <textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                    <InputError message={form.errors.description} />
                </div>

                <aside className="rounded-lg bg-muted/50 p-4 sm:col-span-2" aria-live="polite">
                    {sameTime ? <p className="font-medium text-destructive">Start and end time must differ.</p> : durationMinutes !== null ? <>
                        <p className="font-medium">{isOvernight ? 'Overnight shift' : 'Shift duration preview'}</p>
                        <p className="mt-1 text-sm text-muted-foreground">{form.data.start_time} → {form.data.end_time} · {displayDuration(durationMinutes)}</p>
                        <p className="text-sm text-muted-foreground">Break: {displayDuration(breakMinutes)} · Net scheduled time: {displayDuration(durationMinutes - breakMinutes)}</p>
                    </> : <p className="text-sm text-muted-foreground">Choose both times to preview the shift duration.</p>}
                </aside>

                <div className="flex items-center gap-3 sm:col-span-2">
                    <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save shift'}</Button>
                    <Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link>
                </div>
            </form>
        </main>
    </AppLayout>;
}
