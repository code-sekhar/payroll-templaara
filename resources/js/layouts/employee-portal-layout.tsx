import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

interface EmployeePortalLayoutProps {
    children: ReactNode;
    title: string;
    canAccessManagement: boolean;
    companyName: string;
    employeeName: string;
}

export default function EmployeePortalLayout({ children, title, canAccessManagement, companyName, employeeName }: EmployeePortalLayoutProps) {
    const page = usePage<SharedData>();
    const { name } = page.props;
    const flashMessage = page.props.flash?.success ?? page.props.flash?.warning;
    const currentPath = page.url.split('?')[0].replace(/\/$/, '') || '/';

    return (
        <div className="bg-muted/30 text-foreground flex min-h-screen flex-col">
            <Head title={title} />
            <a
                href="#employee-portal-main"
                className="bg-background focus:ring-ring sr-only z-50 rounded-md px-4 py-3 text-sm font-medium focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:ring-2"
            >
                Skip to content
            </a>
            <header className="bg-background border-b">
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-3 px-3 py-3 sm:px-6 sm:py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex min-w-0 items-center gap-3">
                        <Link
                            href={route('employee.portal')}
                            aria-label="Employee Portal home"
                            className="focus-visible:ring-ring flex min-w-0 items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                        >
                            <span className="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-5 fill-current" aria-hidden="true" />
                            </span>
                            <span className="grid min-w-0 text-left">
                                <span className="text-sm leading-tight font-semibold break-words">{companyName}</span>
                                <span className="text-muted-foreground text-xs break-words">{employeeName}</span>
                                <span className="text-muted-foreground text-[11px]">{name} · Employee Portal</span>
                            </span>
                        </Link>
                    </div>
                    <nav
                        aria-label="Employee portal navigation"
                        className="flex w-full flex-wrap items-center gap-2 border-t pt-3 lg:w-auto lg:justify-end lg:border-t-0 lg:pt-0"
                    >
                        <Button
                            variant={currentPath === '/employee' ? 'secondary' : 'ghost'}
                            className="min-h-11 min-w-0 flex-1 aria-[current=page]:font-semibold aria-[current=page]:underline aria-[current=page]:underline-offset-4 sm:flex-none"
                            asChild
                        >
                            <Link href={route('employee.portal')} aria-current={currentPath === '/employee' ? 'page' : undefined}>
                                Home
                            </Link>
                        </Button>
                        <Button
                            variant={currentPath === '/employee/attendance' ? 'secondary' : 'ghost'}
                            className="min-h-11 min-w-0 flex-1 aria-[current=page]:font-semibold aria-[current=page]:underline aria-[current=page]:underline-offset-4 sm:flex-none"
                            asChild
                        >
                            <Link
                                href={route('employee.attendance.index')}
                                aria-current={currentPath === '/employee/attendance' ? 'page' : undefined}
                            >
                                Attendance
                            </Link>
                        </Button>
                        <Button
                            variant={currentPath === '/employee/leave' || currentPath.startsWith('/employee/leave/') && currentPath !== '/employee/leave/calendar' ? 'secondary' : 'ghost'}
                            className="min-h-11 min-w-0 flex-1 aria-[current=page]:font-semibold aria-[current=page]:underline aria-[current=page]:underline-offset-4 sm:flex-none"
                            asChild
                        >
                            <Link href={route('employee.leave.index')} aria-current={currentPath === '/employee/leave' || currentPath.startsWith('/employee/leave/') && currentPath !== '/employee/leave/calendar' ? 'page' : undefined}>
                                Leave
                            </Link>
                        </Button>
                        <Button
                            variant={currentPath === '/employee/leave/calendar' ? 'secondary' : 'ghost'}
                            className="min-h-11 min-w-0 flex-1 aria-[current=page]:font-semibold aria-[current=page]:underline aria-[current=page]:underline-offset-4 sm:flex-none"
                            asChild
                        >
                            <Link href={route('employee.leave.calendar')} aria-current={currentPath === '/employee/leave/calendar' ? 'page' : undefined}>
                                Leave Calendar
                            </Link>
                        </Button>
                        <Button
                            variant={currentPath === '/employee/notifications' ? 'secondary' : 'ghost'}
                            className="min-h-11 min-w-0 flex-1 aria-[current=page]:font-semibold aria-[current=page]:underline aria-[current=page]:underline-offset-4 sm:flex-none"
                            asChild
                        >
                            <Link
                                href={route('employee.notifications.index')}
                                aria-current={currentPath === '/employee/notifications' ? 'page' : undefined}
                            >
                                Notifications
                            </Link>
                        </Button>
                        <Button
                            variant={currentPath === '/employee/profile' ? 'secondary' : 'ghost'}
                            className="min-h-11 min-w-0 flex-1 aria-[current=page]:font-semibold aria-[current=page]:underline aria-[current=page]:underline-offset-4 sm:flex-none"
                            asChild
                        >
                            <Link href={route('employee.profile.show')} aria-current={currentPath === '/employee/profile' ? 'page' : undefined}>
                                Profile
                            </Link>
                        </Button>
                        {canAccessManagement && (
                            <Button variant="outline" className="min-h-11 max-w-full whitespace-normal sm:flex-none" asChild>
                                <Link href={route('dashboard')}>Management Dashboard</Link>
                            </Button>
                        )}
                        <Button variant="ghost" className="min-h-11 flex-1 sm:flex-none" asChild>
                            <Link href={route('logout')} method="post" as="button">
                                Log out
                            </Link>
                        </Button>
                    </nav>
                </div>
            </header>
            <main
                id="employee-portal-main"
                tabIndex={-1}
                className="mx-auto flex w-full max-w-5xl min-w-0 flex-1 flex-col gap-5 px-3 py-6 focus:outline-hidden sm:gap-6 sm:px-6 sm:py-10"
            >
                {flashMessage && (
                    <p role="status" aria-live="polite" className="bg-card rounded-lg border px-4 py-3 text-sm break-words">
                        {flashMessage}
                    </p>
                )}
                {children}
            </main>
        </div>
    );
}
