import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';

export interface AttendanceNotificationItem {
    id: string;
    title: string;
    message: string;
    attendance_date: string;
    created_at: string;
    is_read: boolean;
    open_url: string;
}

export interface AttendanceNotificationsData {
    notifications: AttendanceNotificationItem[];
    unread_count: number;
}

export default function AttendanceNotificationList({ data }: { data: AttendanceNotificationsData }) {
    return (
        <section aria-label="Attendance notifications" className="grid min-w-0 gap-3">
            {data.notifications.length === 0 ? (
                <Card>
                    <CardContent className="p-5 text-sm text-muted-foreground">
                        You're all caught up. There are no attendance notifications to show.
                    </CardContent>
                </Card>
            ) : (
                data.notifications.map((notification) => (
                    <Card key={notification.id} className="min-w-0">
                        <CardContent className="grid min-w-0 gap-3 p-4 sm:p-5">
                            <div className="flex min-w-0 flex-wrap items-start justify-between gap-2">
                                <h2 className="min-w-0 flex-1 text-base font-semibold break-words">{notification.title}</h2>
                                <Badge variant={notification.is_read ? 'outline' : 'secondary'}>
                                    {notification.is_read ? 'Read' : 'New'}
                                </Badge>
                            </div>
                            <p className="text-sm break-words">{notification.message}</p>
                            <p className="text-muted-foreground text-xs break-words">
                                Attendance date: {notification.attendance_date} · {notification.created_at}
                            </p>
                            <Link
                                href={notification.open_url}
                                method="post"
                                as="button"
                                className="focus-visible:ring-ring inline-flex min-h-11 w-fit max-w-full items-center justify-center rounded-md border px-4 py-2 text-sm font-medium break-words hover:bg-accent focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                View attendance request
                            </Link>
                        </CardContent>
                    </Card>
                ))
            )}
        </section>
    );
}
