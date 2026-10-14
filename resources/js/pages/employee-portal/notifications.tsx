import NotificationCenter, { type NotificationCenterData } from '@/components/notification-center';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head } from '@inertiajs/react';

interface EmployeeNotificationsPageProps {
    notificationCenter: NotificationCenterData;
    routes: { readAll: string; all: string; unread: string };
    employeePortal: {
        companyName: string;
        employeeName: string;
        canAccessManagement: boolean;
    };
}

export default function EmployeeNotificationsPage({ notificationCenter, employeePortal, routes }: EmployeeNotificationsPageProps) {
    return (
        <EmployeePortalLayout
            title="Notifications"
            canAccessManagement={employeePortal.canAccessManagement}
            companyName={employeePortal.companyName}
            employeeName={employeePortal.employeeName}
        >
            <Head title="Notifications" />
            <header className="grid min-w-0 gap-2">
                <p className="text-sm font-medium text-muted-foreground">Updates relevant to your work</p>
                <h1 className="text-3xl font-semibold tracking-tight break-words">Notifications</h1>
            </header>
            <NotificationCenter data={notificationCenter} routes={routes} />
        </EmployeePortalLayout>
    );
}
