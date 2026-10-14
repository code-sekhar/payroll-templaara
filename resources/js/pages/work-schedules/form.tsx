import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type DayType = 'working' | 'off' | 'half_day';
type ScheduleDay = { day_of_week: number; day_type: DayType; shift_id: string };
type ShiftOption = { id: number; name: string; code: string; net_duration_minutes: number };
type Schedule = { id: number; name: string; code: string; description: string | null; status: 'active' | 'inactive'; days: ScheduleDay[] };

const formatDuration = (minutes: number): string => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;

export default function WorkScheduleForm({ schedule, company, shifts, daysOfWeek, dayTypes }: {
    schedule: Schedule | null;
    company: { id: number; name: string };
    shifts: ShiftOption[];
    daysOfWeek: { value: number; label: string }[];
    dayTypes: { value: DayType; label: string }[];
}) {
    const initialDays: ScheduleDay[] = schedule?.days.map((day) => ({ ...day, shift_id: day.shift_id === null ? '' : String(day.shift_id) })) ?? daysOfWeek.map((day) => ({
        day_of_week: day.value,
        day_type: day.value <= 5 ? 'working' : 'off',
        shift_id: day.value <= 5 && shifts[0] ? String(shifts[0].id) : '',
    }));
    const form = useForm({
        name: schedule?.name ?? '',
        code: schedule?.code ?? '',
        description: schedule?.description ?? '',
        days: initialDays,
    });
    const baseUrl = `/companies/${company.id}/work-schedules`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (schedule) {
            form.put(`${baseUrl}/${schedule.id}`);
        } else {
            form.post(baseUrl);
        }
    };
    const updateDay = (index: number, field: 'day_type' | 'shift_id', value: string) => {
        form.setData('days', form.data.days.map((day, dayIndex) => dayIndex === index
            ? { ...day, [field]: value, ...(field === 'day_type' && value === 'off' ? { shift_id: '' } : {}) } as ScheduleDay
            : day));
    };
    const weeklyMinutes = form.data.days.reduce((total, day) => {
        if (day.day_type === 'off') return total;
        return total + (shifts.find((shift) => String(shift.id) === day.shift_id)?.net_duration_minutes ?? 0);
    }, 0);
    const workingDays = form.data.days.filter((day) => day.day_type !== 'off').length;
    const errors = form.errors as Record<string, string | undefined>;

    return <AppLayout breadcrumbs={[{ title: 'Work schedules', href: baseUrl }, { title: schedule ? 'Edit schedule' : 'Create schedule', href: schedule ? `${baseUrl}/${schedule.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={schedule ? 'Edit work schedule' : 'Create work schedule'} />
        <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{schedule ? 'Edit work schedule' : 'Create work schedule'}</h1><p className="mt-1 text-sm text-muted-foreground">Define one weekly pattern for {company.name}. Daily minutes are derived from each selected shift.</p></header>

            <form onSubmit={submit} className="flex flex-col gap-6">
                <section className="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                    <div className="grid gap-2"><Label htmlFor="name">Schedule name</Label><Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /><InputError message={form.errors.name} /></div>
                    <div className="grid gap-2"><Label htmlFor="code">Code</Label><Input id="code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} maxLength={64} placeholder="STANDARD-5-DAY" required /><InputError message={form.errors.code} /></div>
                    <div className="grid gap-2 sm:col-span-2"><Label htmlFor="description">Description</Label><textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><InputError message={form.errors.description} /></div>
                    {schedule && <p className="text-sm text-muted-foreground sm:col-span-2">Status: <span className="font-medium capitalize text-foreground">{schedule.status}</span>. Change status from the schedule list using Activate or Deactivate.</p>}
                </section>

                <section className="overflow-hidden rounded-xl border">
                    <header className="border-b p-5"><h2 className="font-semibold">Weekly pattern</h2><p className="mt-1 text-sm text-muted-foreground">Working and half-day minutes come from the referenced shift, including its break duration.</p></header>
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr>{['Day', 'Day type', 'Shift', 'Net shift time'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                        <tbody>{form.data.days.map((day, index) => {
                            const shift = shifts.find((option) => String(option.id) === day.shift_id);
                            return <tr key={day.day_of_week} className="border-t">
                                <td className="whitespace-nowrap px-4 py-3 font-medium">{daysOfWeek.find((option) => option.value === day.day_of_week)?.label}</td>
                                <td className="min-w-40 px-4 py-3"><select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={day.day_type} onChange={(event) => updateDay(index, 'day_type', event.target.value)}>{dayTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select><InputError message={errors[`days.${index}.day_type`]} /></td>
                                <td className="min-w-56 px-4 py-3"><select disabled={day.day_type === 'off'} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50" value={day.shift_id} onChange={(event) => updateDay(index, 'shift_id', event.target.value)}><option value="">Select shift</option>{shifts.map((option) => <option key={option.id} value={option.id}>{option.name} · {option.code}</option>)}</select><InputError message={errors[`days.${index}.shift_id`]} /></td>
                                <td className="whitespace-nowrap px-4 py-3">{day.day_type === 'off' ? 'Off' : shift ? formatDuration(shift.net_duration_minutes) : '—'}</td>
                            </tr>;
                        })}</tbody>
                    </table></div>
                    <InputError message={form.errors.days} />
                    <div className="flex flex-wrap justify-between gap-2 border-t bg-muted/30 px-5 py-4 text-sm"><span className="font-medium">Weekly summary</span><span>{workingDays} working days · {formatDuration(weeklyMinutes)} / week</span></div>
                </section>

                <div className="flex items-center gap-3"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save schedule'}</Button><Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link>{shifts.length === 0 && <p className="text-sm text-muted-foreground">Create an active shift before configuring working days.</p>}</div>
            </form>
        </main>
    </AppLayout>;
}
