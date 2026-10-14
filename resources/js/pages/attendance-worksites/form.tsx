import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Worksite = { id: number; name: string; latitude: string; longitude: string; radius_meters: number; is_active: boolean };
type FormData = { name: string; latitude: string; longitude: string; radius_meters: string };

export default function AttendanceWorksiteForm({ worksite, company }: { worksite: Worksite | null; company: { id: number; name: string } }) {
    const form = useForm<FormData>({ name: worksite?.name ?? '', latitude: worksite?.latitude ?? '', longitude: worksite?.longitude ?? '', radius_meters: worksite ? String(worksite.radius_meters) : '' });
    const baseUrl = `/companies/${company.id}/attendance-worksites`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (worksite) form.put(`${baseUrl}/${worksite.id}`);
        else form.post(baseUrl);
    };

    return <AppLayout breadcrumbs={[{ title: 'Attendance worksites', href: baseUrl }, { title: worksite ? 'Edit worksite' : 'Create worksite', href: '#' }]}>
        <Head title={worksite ? 'Edit attendance worksite' : 'Create attendance worksite'} />
        <main className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
            <header><h1 className="text-2xl font-semibold">{worksite ? 'Edit worksite' : 'Add attendance worksite'}</h1><p className="mt-1 text-sm text-muted-foreground">Enter the location coordinates and allowed radius in metres. No map or external location service is used.</p></header>
            <form onSubmit={submit} className="grid gap-5 rounded-xl border p-5">
                <div className="grid gap-2"><Label htmlFor="name">Worksite name</Label><Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /><InputError message={form.errors.name} /></div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2"><Label htmlFor="latitude">Latitude</Label><Input id="latitude" inputMode="decimal" value={form.data.latitude} onChange={(event) => form.setData('latitude', event.target.value)} required /><InputError message={form.errors.latitude} /></div>
                    <div className="grid gap-2"><Label htmlFor="longitude">Longitude</Label><Input id="longitude" inputMode="decimal" value={form.data.longitude} onChange={(event) => form.setData('longitude', event.target.value)} required /><InputError message={form.errors.longitude} /></div>
                </div>
                <div className="grid gap-2"><Label htmlFor="radius_meters">Allowed radius (metres)</Label><Input id="radius_meters" type="number" min="1" max="10000" step="1" value={form.data.radius_meters} onChange={(event) => form.setData('radius_meters', event.target.value)} required /><InputError message={form.errors.radius_meters} /><p className="text-xs text-muted-foreground">The server accepts a punch when the reported location is at or within this radius.</p></div>
                <div className="flex flex-wrap items-center gap-3"><Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save worksite'}</Button><Button type="button" asChild variant="outline"><Link href={baseUrl}>Cancel</Link></Button></div>
            </form>
        </main>
    </AppLayout>;
}
