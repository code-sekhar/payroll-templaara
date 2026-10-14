import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Worksite = { id: number; name: string; latitude: string; longitude: string; radius_meters: number; is_active: boolean };

export default function AttendanceWorksitesIndex({ worksites, company, canCreate, canUpdate, canActivate, canDeactivate }: {
    worksites: Worksite[];
    company: { id: number; name: string };
    canCreate: boolean;
    canUpdate: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
}) {
    const baseUrl = `/companies/${company.id}/attendance-worksites`;
    const [message, setMessage] = useState<string | null>(null);

    function changeStatus(worksite: Worksite, active: boolean): void {
        setMessage(null);
        router.patch(`${baseUrl}/${worksite.id}/${active ? 'activate' : 'deactivate'}`, {}, {
            onError: (errors) => setMessage(typeof errors.worksite === 'string' ? errors.worksite : 'The worksite status could not be changed.'),
        });
    }

    return <AppLayout breadcrumbs={[{ title: 'Attendance worksites', href: baseUrl }]}>
        <Head title="Attendance worksites" />
        <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div><h1 className="text-2xl font-semibold">Attendance worksites</h1><p className="mt-1 text-sm text-muted-foreground">Define company locations that can be selected by an attendance policy. Coordinates are used only during a punch.</p></div>
                {canCreate && <Button asChild><Link href={`${baseUrl}/create`}>Add worksite</Link></Button>}
            </header>
            {message && <p role="alert" className="rounded-md border border-destructive/40 p-3 text-sm text-destructive">{message}</p>}
            <section className="grid gap-3">
                {worksites.map((worksite) => <article key={worksite.id} className="flex min-w-0 flex-wrap items-center justify-between gap-4 rounded-xl border p-4">
                    <div className="min-w-0"><h2 className="break-words font-medium">{worksite.name}</h2><p className="break-all text-sm text-muted-foreground">{worksite.latitude}, {worksite.longitude} · {worksite.radius_meters} m radius</p><p className="text-sm">{worksite.is_active ? 'Active' : 'Inactive'}</p></div>
                    <div className="flex flex-wrap items-center gap-2">{canUpdate && <Button asChild variant="outline"><Link href={`${baseUrl}/${worksite.id}/edit`}>Edit</Link></Button>}{worksite.is_active && canDeactivate && <Button variant="outline" onClick={() => changeStatus(worksite, false)}>Deactivate</Button>}{!worksite.is_active && canActivate && <Button variant="outline" onClick={() => changeStatus(worksite, true)}>Activate</Button>}</div>
                </article>)}
                {worksites.length === 0 && <p className="rounded-xl border p-6 text-sm text-muted-foreground">No worksites configured yet.</p>}
            </section>
        </main>
    </AppLayout>;
}
