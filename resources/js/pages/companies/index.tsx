import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface CompanySummary {
    id: number;
    name: string;
    slug: string;
    status: string;
}

export default function CompaniesIndex({ companies }: { companies: CompanySummary[] }) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Companies', href: '/companies' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Companies" />
            <section className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Companies</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Organizations available to your account.</p>
                </div>
                <div className="overflow-hidden rounded-xl border">
                    {companies.length ? companies.map((company) => (
                        <Link key={company.id} href={`/companies/${company.id}`} className="flex items-center justify-between border-b p-4 last:border-b-0 hover:bg-muted/50">
                            <div><p className="font-medium">{company.name}</p><p className="text-muted-foreground text-sm">{company.slug}</p></div>
                            <span className="text-muted-foreground text-sm capitalize">{company.status}</span>
                        </Link>
                    )) : <p className="text-muted-foreground p-5 text-sm">No companies are available for this account.</p>}
                </div>
            </section>
        </AppLayout>
    );
}
