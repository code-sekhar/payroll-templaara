import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { value: string; label: string };
type EmployeeOption = { id: number; employee_code: string; name: string };
type PunchOption = { id: number; punch_type: string; punched_at: string; timezone: string };
type Context = { record: null | { status: string; record_state: string; worked_minutes: number; first_check_in: string | null; last_check_out: string | null }; punches: PunchOption[] };
type Item = { action: 'add' | 'replace' | 'remove'; attendance_punch_id: string; punch_type: string; requested_punched_at: string; notes: string };

export default function AttendanceRegularizationCreate({ company, employees, employeeSearch: initialEmployeeSearch, selectedEmployeeId, attendanceDate, context, self, types, punchTypes }: {
    company: { id: number; name: string; timezone: string };
    employees: EmployeeOption[];
    employeeSearch: string;
    selectedEmployeeId: number | null;
    attendanceDate: string;
    context: Context;
    self: boolean;
    types: Option[];
    punchTypes: Option[];
}) {
    const baseUrl = `/companies/${company.id}/attendance-regularizations`;
    const [employeeSearch, setEmployeeSearch] = useState(initialEmployeeSearch);
    const form = useForm({ employee_id: selectedEmployeeId ? String(selectedEmployeeId) : '', attendance_date: attendanceDate, request_type: 'other', reason: '', employee_note: '', items: [{ action: 'add', attendance_punch_id: '', punch_type: 'check_in', requested_punched_at: '', notes: '' }] as Item[] });
    const itemError = (index: number, field: keyof Item): string | undefined => (form.errors as unknown as Record<string, string | undefined>)[`items.${index}.${field}`];
    const updateItem = (index: number, key: keyof Item, value: string) => form.setData('items', form.data.items.map((item, current) => current === index ? { ...item, [key]: value } : item));
    const addItem = () => { if (form.data.items.length < 10) form.setData('items', [...form.data.items, { action: 'add', attendance_punch_id: '', punch_type: 'check_in', requested_punched_at: '', notes: '' }]); };
    const removeItem = (index: number) => { if (form.data.items.length > 1) form.setData('items', form.data.items.filter((_, current) => current !== index)); };
    const loadContext = () => router.get(self ? `${baseUrl}/self/create` : `${baseUrl}/create`, { employee_id: form.data.employee_id, attendance_date: form.data.attendance_date }, { preserveState: true, replace: true });
    const searchEmployees = () => router.get(`${baseUrl}/create`, { employee_search: employeeSearch }, { preserveState: true, replace: true });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (self) {
            form.transform((data) => ({
                attendance_date: data.attendance_date,
                request_type: data.request_type,
                reason: data.reason,
                employee_note: data.employee_note,
                items: data.items,
            }));
            form.post(`${baseUrl}/self`);
        } else {
            form.post(baseUrl);
        }
    };

    return <AppLayout breadcrumbs={[{ title: 'Attendance regularization', href: baseUrl }, { title: 'New request', href: self ? `${baseUrl}/self/create` : `${baseUrl}/create` }]}>
        <Head title="New attendance regularization request" />
        <main className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">Submit a regularization request</h1><p className="mt-1 text-sm text-muted-foreground">Requests are stored for review. Original punch events and calculated attendance stay unchanged.</p></header>
            <form onSubmit={submit} className="grid gap-6 rounded-xl border p-5">
                {!self && <div className="grid gap-2"><Label htmlFor="employee-search">Find employee</Label><div className="flex gap-2"><input id="employee-search" value={employeeSearch} onChange={(event) => setEmployeeSearch(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); searchEmployees(); } }} placeholder="Name or employee code" className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm" /><Button type="button" variant="outline" onClick={searchEmployees}>Search</Button></div></div>}
                {!self && <div className="grid gap-2"><Label htmlFor="employee_id">Employee</Label><select id="employee_id" value={form.data.employee_id} onChange={(event) => form.setData('employee_id', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm"><option value="">Select employee</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name} · {employee.employee_code}</option>)}</select><InputError message={form.errors.employee_id} /></div>}
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2"><Label htmlFor="attendance_date">Attendance date</Label><input id="attendance_date" type="date" required value={form.data.attendance_date} onChange={(event) => form.setData('attendance_date', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" /><InputError message={form.errors.attendance_date} /></div>
                    <div className="flex items-end"><Button type="button" variant="outline" onClick={loadContext} disabled={!form.data.attendance_date || (!self && !form.data.employee_id)}>Load attendance context</Button></div>
                </div>
                {context.record && <section className="rounded-lg bg-muted/50 p-4 text-sm"><h2 className="font-medium">Current materialized attendance · {context.record.status.replaceAll('_', ' ')}</h2><p className="mt-1 text-muted-foreground">{context.record.first_check_in ?? 'No check-in'} → {context.record.last_check_out ?? 'No check-out'} · {context.record.worked_minutes} minutes · {context.record.record_state.replaceAll('_', ' ')}</p></section>}
                <div className="grid gap-2"><Label htmlFor="request_type">Request type</Label><select id="request_type" value={form.data.request_type} onChange={(event) => form.setData('request_type', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm">{types.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select><InputError message={form.errors.request_type} /></div>
                <div className="grid gap-2"><Label htmlFor="reason">Reason</Label><textarea id="reason" required minLength={10} maxLength={5000} rows={3} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className="rounded-md border border-input bg-background px-3 py-2 text-sm" /><InputError message={form.errors.reason} /></div>
                <div className="grid gap-2"><Label htmlFor="employee_note">Additional note (optional)</Label><textarea id="employee_note" rows={2} value={form.data.employee_note} onChange={(event) => form.setData('employee_note', event.target.value)} className="rounded-md border border-input bg-background px-3 py-2 text-sm" /><InputError message={form.errors.employee_note} /></div>
                <section className="grid gap-4">
                    <header className="flex items-center justify-between gap-3"><div><h2 className="font-semibold">Requested corrections</h2><p className="text-sm text-muted-foreground">Times use {company.timezone} unless the input includes an explicit offset.</p></div><Button type="button" variant="outline" onClick={addItem} disabled={form.data.items.length >= 10}>Add item</Button></header>
                    <InputError message={form.errors.items} />
                    {form.data.items.map((item, index) => <fieldset key={index} className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                        <legend className="px-1 text-sm font-medium">Correction {index + 1}</legend>
                        <div className="grid gap-2"><Label>Action</Label><select value={item.action} onChange={(event) => updateItem(index, 'action', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm"><option value="add">Add missing punch</option><option value="replace">Replace for calculation</option><option value="remove">Request removal</option></select><InputError message={itemError(index, 'action')} /></div>
                        {item.action !== 'add' && <div className="grid gap-2"><Label>Original punch</Label><select value={item.attendance_punch_id} onChange={(event) => updateItem(index, 'attendance_punch_id', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm"><option value="">Select punch</option>{context.punches.map((punch) => <option key={punch.id} value={punch.id}>{punch.punch_type.replace('_', ' ')} · {punch.punched_at} ({punch.timezone})</option>)}</select><InputError message={itemError(index, 'attendance_punch_id')} /></div>}
                        {item.action !== 'remove' && <><div className="grid gap-2"><Label>Punch type</Label><select value={item.punch_type} onChange={(event) => updateItem(index, 'punch_type', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm">{punchTypes.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select><InputError message={itemError(index, 'punch_type')} /></div><div className="grid gap-2"><Label>Requested date and time ({company.timezone})</Label><input type="datetime-local" value={item.requested_punched_at} onChange={(event) => updateItem(index, 'requested_punched_at', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" /><InputError message={itemError(index, 'requested_punched_at')} /></div></>}
                        <div className="grid gap-2 sm:col-span-2"><Label>Item note (optional)</Label><input value={item.notes} onChange={(event) => updateItem(index, 'notes', event.target.value)} maxLength={1000} className="h-10 rounded-md border border-input bg-background px-3 text-sm" /><InputError message={itemError(index, 'notes')} /></div>
                        {form.data.items.length > 1 && <div className="sm:col-span-2"><Button type="button" variant="ghost" onClick={() => removeItem(index)}>Remove item</Button></div>}
                    </fieldset>)}
                </section>
                <div className="flex items-center gap-3"><Button disabled={form.processing}>{form.processing ? 'Submitting…' : 'Submit request'}</Button><Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link></div>
            </form>
        </main>
    </AppLayout>;
}
