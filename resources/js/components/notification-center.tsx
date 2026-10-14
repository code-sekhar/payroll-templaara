import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link, useForm } from '@inertiajs/react';

export interface NotificationCenterItem {
    id: string;
    title: string;
    summary: string;
    category: string;
    created_at: string;
    created_at_iso: string;
    is_read: boolean;
    destination_url: string;
    destination_label: string;
    read_url: string;
    icon: string;
}

export interface NotificationCenterData {
    notifications: NotificationCenterItem[];
    unread_count: number;
    filter: 'all' | 'unread';
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}

interface NotificationCenterProps {
    data: NotificationCenterData;
    routes: { readAll: string; all: string; unread: string };
}

function NotificationCard({ notification }: { notification: NotificationCenterItem }) {
    const form = useForm({});

    return (
        <Card className="min-w-0">
            <CardContent className="grid min-w-0 gap-4 p-4 sm:p-5">
                <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
                    <div className="grid min-w-0 flex-1 gap-1">
                        <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{notification.category}</p>
                        <h2 className="text-base font-semibold break-words">{notification.title}</h2>
                    </div>
                    <Badge variant={notification.is_read ? 'outline' : 'secondary'}>
                        {notification.is_read ? 'Read' : 'Unread'}
                    </Badge>
                </div>
                <p className="text-sm break-words">{notification.summary}</p>
                <div className="flex min-w-0 flex-wrap items-center justify-between gap-3">
                    <time dateTime={notification.created_at_iso} className="text-muted-foreground text-xs">
                        {notification.created_at}
                    </time>
                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" className="min-h-11" asChild>
                            <Link href={notification.destination_url}>{notification.destination_label}</Link>
                        </Button>
                        {!notification.is_read && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="min-h-11"
                                disabled={form.processing}
                                aria-label={`Mark ${notification.title} as read`}
                                onClick={() => form.post(notification.read_url, { preserveScroll: true })}
                            >
                                {form.processing ? 'Marking…' : 'Mark as read'}
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

export default function NotificationCenter({ data, routes }: NotificationCenterProps) {
    const markAll = useForm({});

    return (
        <section className="grid min-w-0 gap-5" aria-label="Notification center">
            <div className="flex min-w-0 flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter notifications">
                    <Button variant={data.filter === 'all' ? 'secondary' : 'outline'} className="min-h-11" asChild>
                        <Link href={routes.all} aria-current={data.filter === 'all' ? 'page' : undefined} preserveState>
                            All
                        </Link>
                    </Button>
                    <Button variant={data.filter === 'unread' ? 'secondary' : 'outline'} className="min-h-11" asChild>
                        <Link href={routes.unread} aria-current={data.filter === 'unread' ? 'page' : undefined} preserveState>
                            Unread
                        </Link>
                    </Button>
                </div>
                <p className="text-sm" aria-label={`${data.unread_count} unread notifications`}>
                    <span className="font-semibold">{data.unread_count}</span> unread
                </p>
            </div>

            {data.unread_count > 0 && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={markAll.processing}
                        onClick={() => markAll.post(routes.readAll, { preserveScroll: true })}
                    >
                        {markAll.processing ? 'Updating…' : 'Mark all as read'}
                    </Button>
                </div>
            )}

            {data.notifications.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-sm">
                        {data.filter === 'unread' ? 'You have no unread notifications.' : 'You have no notifications for this company yet.'}
                    </CardContent>
                </Card>
            ) : (
                <div className="grid min-w-0 gap-3">
                    {data.notifications.map((notification) => (
                        <NotificationCard key={notification.id} notification={notification} />
                    ))}
                </div>
            )}

            {data.pagination.last_page > 1 && (
                <nav aria-label="Notification pages" className="flex flex-wrap items-center gap-2">
                    {data.pagination.links.map((link, index) => (
                        link.url ? (
                            <Button key={`${link.label}-${index}`} variant={link.active ? 'secondary' : 'outline'} size="sm" className="min-h-11" asChild>
                                <Link href={link.url} aria-current={link.active ? 'page' : undefined} preserveState>
                                    {link.label}
                                </Link>
                            </Button>
                        ) : (
                            <span key={`${link.label}-${index}`} className="text-muted-foreground px-3 py-2 text-sm" aria-disabled="true">
                                {link.label}
                            </span>
                        )
                    ))}
                </nav>
            )}
        </section>
    );
}
