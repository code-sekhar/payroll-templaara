import AttendanceNotificationList, { type AttendanceNotificationsData } from '@/components/attendance-notification-list';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

interface AttendanceNotificationsPageProps {
    notifications: AttendanceNotificationsData;
    company: { name: string };
}

export default function AttendanceNotificationsPage({ notifications, company }: AttendanceNotificationsPageProps) {
    return (
        <AppLayout>
            <Head title="Attendance notifications" />
            <div className="mx-auto grid w-full max-w-5xl min-w-0 gap-6 px-4 py-6 sm:px-6">
                <header className="grid min-w-0 gap-2">
                    <p className="break-words text-sm font-medium text-muted-foreground">{company.name}</p>
                    <h1 className="text-3xl font-semibold tracking-tight break-words">Attendance notifications</h1>
                    <p className="text-sm text-muted-foreground">
                        {notifications.unread_count === 0
                            ? 'No unread updates in this list.'
                            : `${notifications.unread_count} unread ${notifications.unread_count === 1 ? 'update' : 'updates'} shown.`}
                    </p>
                </header>
                <AttendanceNotificationList data={notifications} />
            </div>
        </AppLayout>
    );
}
