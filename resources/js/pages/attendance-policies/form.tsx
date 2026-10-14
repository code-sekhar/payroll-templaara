import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Policy = {
    id: number;
    name: string;
    code: string;
    description: string | null;
    status: 'active' | 'inactive';
    grace_in_minutes: number;
    grace_out_minutes: number;
    late_after_minutes: number;
    early_leave_after_minutes: number;
    half_day_before_minutes: number;
    absent_before_minutes: number;
    overtime_enabled: boolean;
    overtime_after_minutes: number;
    location_mode: 'disabled' | 'capture' | 'required' | 'geofence_required';
    worksite_ids: number[];
};

type WorksiteOption = { id: number; name: string };

type FormData = {
    name: string;
    code: string;
    description: string;
    status: 'active' | 'inactive';
    grace_in_minutes: string;
    grace_out_minutes: string;
    late_after_minutes: string;
    early_leave_after_minutes: string;
    half_day_before_minutes: string;
    absent_before_minutes: string;
    overtime_enabled: boolean;
    overtime_after_minutes: string;
    location_mode: 'disabled' | 'capture' | 'required' | 'geofence_required';
    worksite_ids: number[];
};

const minuteFields = [
    ['grace_in_minutes', 'Grace in'],
    ['grace_out_minutes', 'Grace out'],
    ['late_after_minutes', 'Late after'],
    ['early_leave_after_minutes', 'Early leave after'],
    ['half_day_before_minutes', 'Half-day threshold'],
    ['absent_before_minutes', 'Absent threshold'],
    ['overtime_after_minutes', 'Overtime after'],
] as const;

export default function AttendancePolicyForm({ policy, company, worksites, worksitesUrl, canActivate, canDeactivate }: {
    policy: Policy | null;
    company: { id: number; name: string };
    worksites: WorksiteOption[];
    worksitesUrl: string;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const form = useForm<FormData>({
        name: policy?.name ?? '',
        code: policy?.code ?? '',
        description: policy?.description ?? '',
        status: policy?.status ?? 'active',
        grace_in_minutes: String(policy?.grace_in_minutes ?? 0),
        grace_out_minutes: String(policy?.grace_out_minutes ?? 0),
        late_after_minutes: String(policy?.late_after_minutes ?? 0),
        early_leave_after_minutes: String(policy?.early_leave_after_minutes ?? 0),
        half_day_before_minutes: String(policy?.half_day_before_minutes ?? 0),
        absent_before_minutes: String(policy?.absent_before_minutes ?? 0),
        overtime_enabled: policy?.overtime_enabled ?? false,
        overtime_after_minutes: String(policy?.overtime_after_minutes ?? 0),
        location_mode: policy?.location_mode ?? 'disabled',
        worksite_ids: policy?.worksite_ids ?? [],
    });
    const baseUrl = `/companies/${company.id}/attendance-policies`;
    const canChangeStatus = policy === null
        ? canDeactivate
        : policy.status === 'active' ? canDeactivate : canActivate;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (policy) form.put(`${baseUrl}/${policy.id}`);
        else form.post(baseUrl);
    };

    const basicField = (name: 'name' | 'code', label: string) => <div className="grid gap-2">
        <Label htmlFor={name}>{label}</Label>
        <Input id={name} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} required />
        <InputError message={form.errors[name]} />
    </div>;

    return <AppLayout breadcrumbs={[{ title: 'Attendance policies', href: baseUrl }, { title: policy ? 'Edit policy' : 'Create policy', href: policy ? `${baseUrl}/${policy.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={policy ? 'Edit attendance policy' : 'Create attendance policy'} />
        <main className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 className="text-2xl font-semibold">{policy ? 'Edit attendance policy' : 'Create attendance policy'}</h1>
                <p className="mt-1 text-sm text-muted-foreground">Reusable rule configuration for {company.name}. Values are stored as minutes; no attendance calculations are performed here.</p>
            </header>

            <form onSubmit={submit} className="grid gap-6">
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2">
                    <h2 className="text-lg font-medium sm:col-span-2">Basic information</h2>
                    {basicField('name', 'Policy name')}
                    {basicField('code', 'Policy code')}
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <select id="status" disabled={!canChangeStatus} className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as FormData['status'])}>
                            <option value="active">Active</option><option value="inactive">Inactive</option>
                        </select>
                        <InputError message={form.errors.status} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="description">Description</Label>
                        <textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                        <InputError message={form.errors.description} />
                    </div>
                </section>

                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2">
                    <h2 className="text-lg font-medium sm:col-span-2">Attendance timing rules</h2>
                    {minuteFields.slice(0, 4).map(([name, label]) => <div key={name} className="grid gap-2">
                        <Label htmlFor={name}>{label} (minutes)</Label>
                        <Input id={name} type="number" min="0" max="1440" step="1" value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} required />
                        <InputError message={form.errors[name]} />
                    </div>)}
                    <p className="text-sm text-muted-foreground sm:col-span-2">Grace-in must not exceed the late threshold. Grace-out must not exceed the early-leave threshold.</p>
                </section>

                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2">
                    <h2 className="text-lg font-medium sm:col-span-2">Day classification thresholds</h2>
                    {minuteFields.slice(4, 6).map(([name, label]) => <div key={name} className="grid gap-2">
                        <Label htmlFor={name}>{label} (minutes)</Label>
                        <Input id={name} type="number" min="0" max="1440" step="1" value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} required />
                        <InputError message={form.errors[name]} />
                    </div>)}
                </section>

                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2">
                    <h2 className="text-lg font-medium sm:col-span-2">Overtime</h2>
                    <label className="flex items-center gap-3 text-sm sm:col-span-2">
                        <input type="checkbox" checked={form.data.overtime_enabled} onChange={(event) => form.setData('overtime_enabled', event.target.checked)} className="size-4 rounded border-input" />
                        Enable overtime eligibility under this policy
                    </label>
                    <InputError message={form.errors.overtime_enabled} />
                    <div className="grid gap-2">
                        <Label htmlFor="overtime_after_minutes">Overtime after (minutes)</Label>
                        <Input id="overtime_after_minutes" type="number" min="0" max="1440" step="1" value={form.data.overtime_after_minutes} onChange={(event) => form.setData('overtime_after_minutes', event.target.value)} required disabled={!form.data.overtime_enabled} />
                        <InputError message={form.errors.overtime_after_minutes} />
                    </div>
                    <p className="self-center text-sm text-muted-foreground">This only stores the threshold. Overtime calculation is not part of this module.</p>
                </section>

                <section className="grid gap-4 rounded-xl border p-5">
                    <div><h2 className="text-lg font-medium">Punch location</h2><p className="mt-1 text-sm text-muted-foreground">Location is requested only when an employee presses Punch In or Punch Out. Browser location is evidence and can be spoofed.</p></div>
                    <div className="grid gap-2"><Label htmlFor="location_mode">Location rule</Label><select id="location_mode" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.location_mode} onChange={(event) => form.setData('location_mode', event.target.value as FormData['location_mode'])}>
                        <option value="disabled">Disabled — do not request or store location</option>
                        <option value="capture">Optional — record location if the employee allows it</option>
                        <option value="required">Required — employee must provide a location reading</option>
                        <option value="geofence_required">Worksite required — location must be within a selected worksite</option>
                    </select><InputError message={form.errors.location_mode} /></div>
                    {form.data.location_mode === 'geofence_required' && <fieldset className="grid gap-2">
                        <legend className="text-sm font-medium">Allowed worksites</legend>
                        {worksites.length === 0 && <p className="text-sm text-destructive">No active worksites are available. <Link className="underline underline-offset-4" href={worksitesUrl}>Add a worksite</Link> before saving this rule.</p>}
                        {worksites.map((worksite) => <label key={worksite.id} className="flex min-h-11 items-center gap-3 rounded-md border px-3 py-2 text-sm"><input type="checkbox" checked={form.data.worksite_ids.includes(worksite.id)} onChange={(event) => form.setData('worksite_ids', event.target.checked ? [...form.data.worksite_ids, worksite.id] : form.data.worksite_ids.filter((id) => id !== worksite.id))} className="size-4 rounded border-input" />{worksite.name}</label>)}
                        <InputError message={form.errors.worksite_ids} />
                        <p className="text-xs text-muted-foreground">A punch is accepted inside any one selected active worksite’s configured radius.</p>
                    </fieldset>}
                </section>

                <div className="flex items-center gap-3">
                    <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save policy'}</Button>
                    <Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link>
                </div>
            </form>
        </main>
    </AppLayout>;
}
