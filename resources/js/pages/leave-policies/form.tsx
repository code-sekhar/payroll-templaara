import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type LeaveTypeOption = { id: number; name: string; code: string; allows_half_day: boolean; status?: 'active' | 'inactive' };
type Policy = { id: number; leave_type_id: number; name: string; code: string; description: string | null; status: 'active' | 'inactive'; entitlement_days: string; accrual_method: 'none' | 'upfront' | 'accrual'; accrual_frequency: 'none' | 'monthly' | 'quarterly' | 'yearly'; carry_forward_enabled: boolean; max_carry_forward_days: string | null; max_balance_days: string | null; allow_negative_balance: boolean; max_negative_balance_days: string | null; minimum_request_days: string; maximum_request_days: string | null; minimum_notice_days: number; allows_half_day: boolean; requires_reason: boolean };
type FormData = { leave_type_id: string; name: string; code: string; description: string; status: 'active' | 'inactive'; entitlement_days: string; accrual_method: Policy['accrual_method']; accrual_frequency: Policy['accrual_frequency']; carry_forward_enabled: boolean; max_carry_forward_days: string; max_balance_days: string; allow_negative_balance: boolean; max_negative_balance_days: string; minimum_request_days: string; maximum_request_days: string; minimum_notice_days: string; allows_half_day: boolean; requires_reason: boolean };

export default function LeavePolicyForm({ policy, leaveTypes, company, canActivate, canDeactivate }: {
    policy: Policy | null;
    leaveTypes: LeaveTypeOption[];
    company: { id: number; name: string };
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const form = useForm<FormData>({
        leave_type_id: String(policy?.leave_type_id ?? leaveTypes[0]?.id ?? ''), name: policy?.name ?? '', code: policy?.code ?? '', description: policy?.description ?? '', status: policy?.status ?? 'active',
        entitlement_days: policy?.entitlement_days ?? '0.00', accrual_method: policy?.accrual_method ?? 'none', accrual_frequency: policy?.accrual_frequency ?? 'none',
        carry_forward_enabled: policy?.carry_forward_enabled ?? false, max_carry_forward_days: policy?.max_carry_forward_days ?? '', max_balance_days: policy?.max_balance_days ?? '',
        allow_negative_balance: policy?.allow_negative_balance ?? false, max_negative_balance_days: policy?.max_negative_balance_days ?? '', minimum_request_days: policy?.minimum_request_days ?? '1.00', maximum_request_days: policy?.maximum_request_days ?? '',
        minimum_notice_days: String(policy?.minimum_notice_days ?? 0), allows_half_day: policy?.allows_half_day ?? false, requires_reason: policy?.requires_reason ?? false,
    });
    const baseUrl = `/companies/${company.id}/leave-policies`;
    const selectedLeaveType = leaveTypes.find((type) => String(type.id) === form.data.leave_type_id);
    const canChangeStatus = policy === null ? canDeactivate : policy.status === 'active' ? canDeactivate : canActivate;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (policy) form.put(`${baseUrl}/${policy.id}`);
        else form.post(baseUrl);
    };
    const decimalInput = (name: keyof FormData, label: string, required = false) => <div className="grid gap-2">
        <Label htmlFor={name}>{label} (days)</Label><Input id={name} type="number" min="0" max="999999.99" step="0.01" required={required} value={String(form.data[name])} onChange={(event) => form.setData(name, event.target.value)} /><InputError message={form.errors[name as keyof typeof form.errors]} />
    </div>;
    const basicInput = (name: 'name' | 'code', label: string) => <div className="grid gap-2"><Label htmlFor={name}>{label}</Label><Input id={name} value={form.data[name]} onChange={(event) => form.setData(name, name === 'code' ? event.target.value.toUpperCase() : event.target.value)} required /><InputError message={form.errors[name]} /></div>;

    return <AppLayout breadcrumbs={[{ title: 'Leave Policies', href: baseUrl }, { title: policy ? 'Edit policy' : 'Create policy', href: policy ? `${baseUrl}/${policy.id}/edit` : `${baseUrl}/create` }]}>
        <Head title={policy ? 'Edit leave policy' : 'Create leave policy'} />
        <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{policy ? 'Edit leave policy' : 'Create leave policy'}</h1><p className="mt-1 text-sm text-muted-foreground">Configure reusable rules for {company.name}. Entitlements are definitions only; balances, accrual, requests, and approvals are not managed here.</p></header>
            <form onSubmit={submit} className="grid gap-5">
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"><h2 className="text-lg font-medium sm:col-span-2">Identity</h2>
                    <div className="grid gap-2 sm:col-span-2"><Label htmlFor="leave_type_id">Leave type</Label><select id="leave_type_id" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.leave_type_id} onChange={(event) => {
                        const nextType = leaveTypes.find((type) => String(type.id) === event.target.value);
                        form.setData((data) => ({ ...data, leave_type_id: event.target.value, allows_half_day: nextType?.allows_half_day ? data.allows_half_day : false, minimum_request_days: nextType?.allows_half_day ? data.minimum_request_days : (Number(data.minimum_request_days) < 1 ? '1.00' : data.minimum_request_days) }));
                    }} required>{leaveTypes.map((type) => <option key={type.id} value={type.id}>{type.name} ({type.code}){type.status === 'inactive' ? ' — Inactive (existing policy)' : ''}</option>)}</select><InputError message={form.errors.leave_type_id} /></div>
                    {basicInput('name', 'Policy name')}{basicInput('code', 'Policy code')}
                    <div className="grid gap-2"><Label htmlFor="status">Status</Label><select id="status" disabled={!canChangeStatus} className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as FormData['status'])}><option value="active">Active</option><option value="inactive">Inactive</option></select><InputError message={form.errors.status} /></div>
                    <div className="grid gap-2 sm:col-span-2"><Label htmlFor="description">Description</Label><textarea id="description" rows={3} maxLength={10000} className="rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><InputError message={form.errors.description} /></div>
                </section>
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"><h2 className="text-lg font-medium sm:col-span-2">Entitlement</h2>
                    {decimalInput('entitlement_days', 'Annual entitlement', true)}
                    <div className="grid gap-2"><Label htmlFor="accrual_method">Accrual method</Label><select id="accrual_method" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.accrual_method} onChange={(event) => {
                        const method = event.target.value as FormData['accrual_method'];
                        form.setData((data) => ({ ...data, accrual_method: method, accrual_frequency: method === 'accrual' ? (data.accrual_frequency === 'none' ? 'monthly' : data.accrual_frequency) : 'none' }));
                    }}><option value="none">None</option><option value="upfront">Upfront</option><option value="accrual">Accrual</option></select><InputError message={form.errors.accrual_method} /></div>
                    <div className="grid gap-2"><Label htmlFor="accrual_frequency">Accrual frequency</Label><select id="accrual_frequency" disabled={form.data.accrual_method !== 'accrual'} className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60" value={form.data.accrual_frequency} onChange={(event) => form.setData('accrual_frequency', event.target.value as FormData['accrual_frequency'])}><option value="none">None</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select><InputError message={form.errors.accrual_frequency} /></div>
                </section>
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"><h2 className="text-lg font-medium sm:col-span-2">Carry forward and balance cap</h2>
                    <label className="flex items-center gap-3 text-sm sm:col-span-2"><input type="checkbox" checked={form.data.carry_forward_enabled} onChange={(event) => form.setData((data) => ({ ...data, carry_forward_enabled: event.target.checked, max_carry_forward_days: event.target.checked ? data.max_carry_forward_days : '' }))} className="size-4 rounded border-input" />Enable carry forward</label><InputError message={form.errors.carry_forward_enabled} />
                    {form.data.carry_forward_enabled && decimalInput('max_carry_forward_days', 'Maximum carry-forward', true)}
                    {decimalInput('max_balance_days', 'Maximum balance (optional)')}
                    <p className="text-sm text-muted-foreground sm:col-span-2">Carry-forward and maximum balance are configuration only; no year-end transfer or employee balance is calculated.</p>
                </section>
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"><h2 className="text-lg font-medium sm:col-span-2">Request rules</h2>
                    {decimalInput('minimum_request_days', 'Minimum request', true)}{decimalInput('maximum_request_days', 'Maximum request (optional)')}
                    <div className="grid gap-2"><Label htmlFor="minimum_notice_days">Minimum notice (days)</Label><Input id="minimum_notice_days" type="number" min="0" max="65535" step="1" value={form.data.minimum_notice_days} onChange={(event) => form.setData('minimum_notice_days', event.target.value)} required /><InputError message={form.errors.minimum_notice_days} /></div>
                    <label className="flex items-center gap-3 text-sm"><input type="checkbox" checked={form.data.allows_half_day} disabled={!selectedLeaveType?.allows_half_day} onChange={(event) => form.setData((data) => ({ ...data, allows_half_day: event.target.checked, minimum_request_days: !event.target.checked && Number(data.minimum_request_days) < 1 ? '1.00' : data.minimum_request_days }))} className="size-4 rounded border-input" />Allow half-day requests{!selectedLeaveType?.allows_half_day && <span className="text-xs text-muted-foreground">(leave type does not allow it)</span>}</label><InputError message={form.errors.allows_half_day} />
                    <label className="flex items-center gap-3 text-sm"><input type="checkbox" checked={form.data.requires_reason} onChange={(event) => form.setData('requires_reason', event.target.checked)} className="size-4 rounded border-input" />Require a reason</label><InputError message={form.errors.requires_reason} />
                    <p className="text-sm text-muted-foreground sm:col-span-2">When half-day requests are disabled, minimum request duration must be at least 1.00 day.</p>
                </section>
                <section className="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"><h2 className="text-lg font-medium sm:col-span-2">Negative balance</h2>
                    <label className="flex items-center gap-3 text-sm sm:col-span-2"><input type="checkbox" checked={form.data.allow_negative_balance} onChange={(event) => form.setData((data) => ({ ...data, allow_negative_balance: event.target.checked, max_negative_balance_days: event.target.checked ? data.max_negative_balance_days : '' }))} className="size-4 rounded border-input" />Allow negative balance</label><InputError message={form.errors.allow_negative_balance} />
                    {form.data.allow_negative_balance && decimalInput('max_negative_balance_days', 'Maximum negative balance', true)}
                    <p className="text-sm text-muted-foreground sm:col-span-2">A positive maximum is required when negative balance is enabled.</p>
                </section>
                <div className="flex items-center gap-3"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save leave policy'}</Button><Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link></div>
            </form>
        </main>
    </AppLayout>;
}
