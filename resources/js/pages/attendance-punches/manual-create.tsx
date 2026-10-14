import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type EmployeeOption = { id: number; employee_code: string; name: string };

export default function ManualAttendancePunchCreate({ employees, employeeSearch: initialSearch, company }: {
    employees: EmployeeOption[];
    employeeSearch: string;
    company: { id: number; name: string; timezone: string };
}) {
    const form = useForm({
        employee_id: '',
        punch_type: 'check_in' as 'check_in' | 'check_out',
        punched_at: '',
        notes: '',
        latitude: '',
        longitude: '',
        location_accuracy_meters: '',
        device_identifier: '',
        external_reference: '',
    });
    const [employeeSearch, setEmployeeSearch] = useState(initialSearch);
    const baseUrl = `/companies/${company.id}/attendance-punches`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${baseUrl}/manual`);
    };
    const searchEmployees = () => router.get(`${baseUrl}/manual/create`, { employee_search: employeeSearch }, { preserveState: true, replace: true });

    return <AppLayout breadcrumbs={[{ title: 'Attendance punches', href: baseUrl }, { title: 'Record manual punch', href: `${baseUrl}/manual/create` }]}>
        <Head title="Record manual attendance punch" />
        <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 className="text-2xl font-semibold">Record manual punch</h1>
                <p className="mt-1 text-sm text-muted-foreground">This creates an immutable MANUAL source event for {company.name}.</p>
            </header>

            <form onSubmit={submit} className="grid gap-6 rounded-xl border p-5 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="employee-search">Find an employee</Label>
                    <div className="flex gap-2">
                        <input id="employee-search" value={employeeSearch} onChange={(event) => setEmployeeSearch(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); searchEmployees(); } }} placeholder="Name or employee code" className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm" />
                        <Button type="button" variant="outline" onClick={searchEmployees}>Search</Button>
                    </div>
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="employee_id">Employee</Label>
                    <select id="employee_id" required value={form.data.employee_id} onChange={(event) => form.setData('employee_id', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Select employee</option>
                        {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name} · {employee.employee_code}</option>)}
                    </select>
                    <InputError message={form.errors.employee_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="punch_type">Punch type</Label>
                    <select id="punch_type" value={form.data.punch_type} onChange={(event) => form.setData('punch_type', event.target.value as 'check_in' | 'check_out')} className="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="check_in">Check in</option><option value="check_out">Check out</option>
                    </select>
                    <InputError message={form.errors.punch_type} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="punched_at">Punch date and time ({company.timezone})</Label>
                    <input id="punched_at" type="datetime-local" required value={form.data.punched_at} onChange={(event) => form.setData('punched_at', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <InputError message={form.errors.punched_at} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="notes">Reason for manual entry</Label>
                    <textarea id="notes" required minLength={5} maxLength={10000} rows={3} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                    <InputError message={form.errors.notes} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="latitude">Latitude (optional)</Label>
                    <input id="latitude" type="number" step="any" min="-90" max="90" value={form.data.latitude} onChange={(event) => form.setData('latitude', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <InputError message={form.errors.latitude} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="longitude">Longitude (optional)</Label>
                    <input id="longitude" type="number" step="any" min="-180" max="180" value={form.data.longitude} onChange={(event) => form.setData('longitude', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <InputError message={form.errors.longitude} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="location_accuracy_meters">Location accuracy in meters</Label>
                    <input id="location_accuracy_meters" type="number" step="0.01" min="0" value={form.data.location_accuracy_meters} onChange={(event) => form.setData('location_accuracy_meters', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <InputError message={form.errors.location_accuracy_meters} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="device_identifier">Device identifier</Label>
                    <input id="device_identifier" maxLength={255} value={form.data.device_identifier} onChange={(event) => form.setData('device_identifier', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <InputError message={form.errors.device_identifier} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="external_reference">External reference (optional)</Label>
                    <input id="external_reference" maxLength={255} value={form.data.external_reference} onChange={(event) => form.setData('external_reference', event.target.value)} className="h-10 rounded-md border border-input bg-background px-3 text-sm" />
                    <InputError message={form.errors.external_reference} />
                </div>
                <div className="flex items-center gap-3 sm:col-span-2">
                    <Button disabled={form.processing || employees.length === 0}>{form.processing ? 'Saving…' : 'Record punch'}</Button>
                    <Link href={baseUrl} className="text-sm underline underline-offset-4">Cancel</Link>
                </div>
                {employees.length === 0 && <p className="text-sm text-muted-foreground sm:col-span-2">No employees matched. Search again or add an employee first.</p>}
            </form>
        </main>
    </AppLayout>;
}
