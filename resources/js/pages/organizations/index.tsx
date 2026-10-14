import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type RecordRow = { id: number; name: string; code: string; status: string; city?: string | null; level?: number | null };
type PaginationLink = { url: string | null; label: string; active: boolean };

export default function OrganizationIndex({ type, title, records, filters, canCreate }: {
    type: 'branches' | 'departments' | 'designations'; title: string;
    records: { data: RecordRow[]; links: PaginationLink[]; total: number };
    filters: { search?: string }; canCreate: boolean;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(`/${type}`, { search }, { preserveState: true, replace: true });
    };

    return <AppLayout breadcrumbs={[{ title, href: `/${type}` }]}>
        <Head title={title} />
        <main className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><h1 className="text-2xl font-semibold">{title}</h1><p className="mt-1 text-sm text-muted-foreground">Manage {title.toLowerCase()} for the active company.</p></div>
                {canCreate && <Link href={`/${type}/create`} className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Add {type === 'branches' ? 'Branch' : type === 'departments' ? 'Department' : 'Designation'}</Link>}
            </header>
            <form onSubmit={submit} className="flex max-w-xl gap-2">
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={`Search ${title.toLowerCase()}`} className="h-10 min-w-0 flex-1 rounded-md border border-input bg-background px-3 text-sm" />
                <button className="rounded-md border px-4 text-sm">Search</button>
            </form>
            <div className="overflow-hidden rounded-xl border">
                <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr><th className="px-4 py-3 font-medium">Name</th><th className="px-4 py-3 font-medium">Code</th>{type === 'branches' && <th className="px-4 py-3 font-medium">City</th>}{type === 'designations' && <th className="px-4 py-3 font-medium">Level</th>}<th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3" /></tr></thead>
                    <tbody>{records.data.map((record) => <tr key={record.id} className="border-t">
                        <td className="px-4 py-3 font-medium">{record.name}</td><td className="px-4 py-3 text-muted-foreground">{record.code}</td>
                        {type === 'branches' && <td className="px-4 py-3">{record.city ?? '—'}</td>}{type === 'designations' && <td className="px-4 py-3">{record.level ?? '—'}</td>}
                        <td className="px-4 py-3"><span className="rounded-full bg-muted px-2.5 py-1 text-xs capitalize">{record.status}</span></td>
                        <td className="px-4 py-3 text-right"><Link className="underline underline-offset-4" href={`/${type}/${record.id}`}>Edit</Link></td>
                    </tr>)}</tbody>
                </table></div>
                {records.data.length === 0 && <p className="p-6 text-sm text-muted-foreground">No {title.toLowerCase()} found. Add one to get started.</p>}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground">
                    <span>{records.total} total</span><nav className="flex gap-1">{records.links.map((link, index) => link.url
                        ? <Link key={index} href={link.url} className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        : <span key={index} className="px-2 py-1 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
                </footer>
            </div>
        </main>
    </AppLayout>;
}
