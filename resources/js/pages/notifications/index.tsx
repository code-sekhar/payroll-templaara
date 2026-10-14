import NotificationCenter, { type NotificationCenterData } from '@/components/notification-center';
import AppLayout from '@/layouts/app-layout';
import { Head, usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

interface NotificationCenterPageProps {
    notificationCenter: NotificationCenterData;
    companyName: string;
    routes: { readAll: string; all: string; unread: string };
}

export default function NotificationCenterPage({ notificationCenter, companyName, routes }: NotificationCenterPageProps) {
    const flash = usePage<SharedData>().props.flash;

    return (
        <AppLayout>
            <Head title="Notifications" />
            <div className="mx-auto grid w-full max-w-5xl min-w-0 gap-6 px-4 py-6 sm:px-6">
                <header className="grid min-w-0 gap-2">
                    <p className="text-muted-foreground break-words text-sm font-medium">{companyName}</p>
                    <h1 className="text-3xl font-semibold tracking-tight break-words">Notifications</h1>
                    <p className="text-muted-foreground text-sm">Updates related to resources you can currently access in this company.</p>
                </header>
                {(flash?.success || flash?.warning) && (
                    <p role="status" aria-live="polite" className="bg-card rounded-lg border px-4 py-3 text-sm break-words">
                        {flash.success ?? flash.warning}
                    </p>
                )}
                <NotificationCenter data={notificationCenter} routes={routes} />
            </div>
        </AppLayout>
    );
}
