import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Day = { date: string; dayNumber: number; approvedCount: number; pendingCount: number };
type Event = {
    employeeName: string;
    employeeCode: string | null;
    leaveType: string;
    status: 'approved' | 'pending';
    statusLabel: 'Approved' | 'Pending';
    amount: string;
    dayType: string;
    href: string;
};
type SelectedDay = {
    date: string;
    data: Event[];
    total: number;
    current_page: number;
    last_page: number;
    per_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Filters = { search: string; leave_type_id: string | number; status: string; department_id: string | number };
type Option = { id: number; name: string };
type Calendar = {
    month: string;
    monthLabel: string;
    previousMonth: string;
    nextMonth: string;
    today: string;
    selectedDate: string;
    firstWeekdayOffset: number;
    timezone: string;
    weekdays: string[];
    days: Day[];
    approvedEvents: number;
    pendingEvents: number;
    selectedDay: SelectedDay;
    filters: Filters;
    leaveTypes: Option[];
    departments: Option[];
    canFilterDepartments: boolean;
    indexUrl: string;
};

function shortDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-');
    return `${day}/${month}/${year}`;
}

export default function LeaveTeamCalendar({ calendar }: { calendar: Calendar }) {
    const [filters, setFilters] = useState<Filters>(calendar.filters);
    const query = (month: string, date?: string) => ({
        month,
        ...(date ? { date } : {}),
        search: filters.search || undefined,
        leave_type_id: filters.leave_type_id || undefined,
        status: filters.status || 'all',
        ...(calendar.canFilterDepartments ? { department_id: filters.department_id || undefined } : {}),
    });
    const submitFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(calendar.indexUrl, query(calendar.month, calendar.selectedDate), { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Team Leave Calendar', href: calendar.indexUrl }]}>
            <Head title="Team Leave Calendar" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-5 p-3 sm:p-4 md:gap-6 md:p-6">
                <header className="flex min-w-0 flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold tracking-tight">Team Leave Calendar</h1>
                        <p className="mt-1 text-sm text-muted-foreground">Approved and pending leave within your authorized review scope.</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2" aria-label="Calendar month navigation">
                        <Button asChild variant="outline" size="sm" className="min-h-11"><Link href={calendar.indexUrl} data={{ ...query(calendar.previousMonth) }}>Previous</Link></Button>
                        <Button asChild variant="outline" size="sm" className="min-h-11"><Link href={calendar.indexUrl} data={{ ...query(calendar.today.slice(0, 7), calendar.today) }}>Today</Link></Button>
                        <Button asChild variant="outline" size="sm" className="min-h-11"><Link href={calendar.indexUrl} data={{ ...query(calendar.nextMonth) }}>Next</Link></Button>
                    </div>
                </header>

                <form onSubmit={submitFilters} className="grid min-w-0 gap-3 rounded-xl border bg-card p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-4">
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>Employee</span>
                        <input aria-label="Search authorized Employees by name or code" className="h-11 min-w-0 rounded-md border bg-background px-3" placeholder="Name or employee code" maxLength={100} value={filters.search} onChange={event => setFilters({ ...filters, search: event.target.value })} />
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>Leave type</span>
                        <select className="h-11 min-w-0 rounded-md border bg-background px-3" value={filters.leave_type_id} onChange={event => setFilters({ ...filters, leave_type_id: event.target.value })}>
                            <option value="">All leave types</option>{calendar.leaveTypes.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}
                        </select>
                    </label>
                    <label className="grid min-w-0 gap-1 text-sm">
                        <span>Status</span>
                        <select className="h-11 min-w-0 rounded-md border bg-background px-3" value={filters.status} onChange={event => setFilters({ ...filters, status: event.target.value })}>
                            <option value="all">Approved and pending</option><option value="approved">Approved</option><option value="pending">Pending</option>
                        </select>
                    </label>
                    {calendar.canFilterDepartments && <label className="grid min-w-0 gap-1 text-sm">
                        <span>Department</span>
                        <select className="h-11 min-w-0 rounded-md border bg-background px-3" value={filters.department_id} onChange={event => setFilters({ ...filters, department_id: event.target.value })}>
                            <option value="">All departments</option>{calendar.departments.map(department => <option key={department.id} value={department.id}>{department.name}</option>)}
                        </select>
                    </label>}
                    <div className="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-4">
                        <Button type="submit" className="min-h-11">Apply filters</Button>
                        <Button type="button" variant="outline" className="min-h-11" onClick={() => {
                            setFilters({ search: '', leave_type_id: '', status: 'all', department_id: '' });
                            router.get(calendar.indexUrl, { month: calendar.month, date: calendar.selectedDate }, { replace: true });
                        }}>Clear filters</Button>
                    </div>
                </form>

                <section aria-labelledby="calendar-heading" className="grid min-w-0 gap-3 rounded-xl border bg-card p-3 sm:p-4">
                    <div className="flex min-w-0 flex-wrap items-center justify-between gap-3">
                        <h2 id="calendar-heading" className="text-xl font-semibold">{calendar.monthLabel}</h2>
                        <p className="text-sm text-muted-foreground">{calendar.approvedEvents} approved · {calendar.pendingEvents} pending events</p>
                    </div>
                    <div className="grid grid-cols-7 gap-1 sm:gap-2" aria-label={`${calendar.monthLabel} leave events`}>
                        {calendar.weekdays.map(day => <div key={day} className="min-w-0 py-1 text-center text-[10px] font-medium text-muted-foreground sm:text-xs">{day.slice(0, 3)}</div>)}
                        {Array.from({ length: calendar.firstWeekdayOffset }, (_, index) => <div key={`empty-${index}`} aria-hidden="true" />)}
                        {calendar.days.map(day => {
                            const selected = day.date === calendar.selectedDate;
                            const isToday = day.date === calendar.today;
                            const countLabel = `${day.approvedCount} approved ${day.pendingCount} pending`;
                            return (
                                <Link
                                    key={day.date}
                                    href={calendar.indexUrl}
                                    data={{ ...query(calendar.month, day.date) }}
                                    aria-current={isToday ? 'date' : undefined}
                                    aria-label={`${shortDate(day.date)}. ${countLabel}${isToday ? '. Today' : ''}${selected ? '. Selected date' : ''}`}
                                    className={`flex min-h-[4.25rem] min-w-0 flex-col items-start gap-1 rounded-lg border p-1.5 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-24 sm:p-2 ${selected ? 'border-primary bg-primary/5' : 'hover:bg-muted/60'} ${isToday ? 'font-semibold' : ''}`}
                                >
                                    <span className="flex w-full items-center justify-between gap-1 text-xs sm:text-sm"><span>{day.dayNumber}</span>{isToday && <span className="text-[9px] text-muted-foreground sm:text-[10px]">Today</span>}</span>
                                    {day.approvedCount > 0 && <span className="max-w-full truncate text-[9px] leading-tight text-foreground sm:text-xs">A {day.approvedCount} approved</span>}
                                    {day.pendingCount > 0 && <span className="max-w-full truncate text-[9px] leading-tight text-muted-foreground sm:text-xs">P {day.pendingCount} pending</span>}
                                </Link>
                            );
                        })}
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-2 border-t pt-3 text-xs text-muted-foreground" aria-label="Calendar legend">
                        <span><span aria-hidden="true" className="font-semibold text-foreground">A</span> Approved leave events</span>
                        <span><span aria-hidden="true" className="font-semibold">P</span> Pending leave events</span>
                    </div>
                    <p className="text-xs text-muted-foreground">Dates and times follow the company timezone ({calendar.timezone}). Holiday and weekly-off markers are omitted because employees may have different assigned calendars and schedules.</p>
                </section>

                <section aria-labelledby="selected-day-heading" className="grid min-w-0 gap-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <h2 id="selected-day-heading" className="text-lg font-semibold">Leave on {shortDate(calendar.selectedDay.date)}</h2>
                            <p className="text-sm text-muted-foreground">{calendar.selectedDay.total} authorized event{calendar.selectedDay.total === 1 ? '' : 's'} for this day</p>
                        </div>
                    </div>
                    {calendar.selectedDay.data.length > 0 ? (
                        <ul className="grid min-w-0 gap-2">
                            {calendar.selectedDay.data.map((event, index) => (
                                <li key={`${event.href}-${index}`} className="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                                    <div className="grid min-w-0 gap-1">
                                        <p className="break-words font-medium">{event.employeeName}</p>
                                        <p className="break-all text-sm text-muted-foreground">{event.employeeCode ? `${event.employeeCode} · ` : ''}{event.leaveType}</p>
                                        <p className="text-sm text-muted-foreground">{event.dayType} · {event.amount} {event.amount === '1.00' ? 'day' : 'days'}</p>
                                    </div>
                                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                                        <Badge variant={event.status === 'approved' ? 'default' : 'secondary'}>{event.statusLabel}</Badge>
                                        <Button asChild variant="outline" className="min-h-11"><Link href={event.href}>View request</Link></Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">No approved or pending leave events match this date and the selected filters.</p>
                    )}
                    {calendar.selectedDay.total > calendar.selectedDay.per_page && <nav aria-label="Selected day event pages" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 text-sm">
                        <span>Page {calendar.selectedDay.current_page} of {calendar.selectedDay.last_page}</span>
                        <div className="flex gap-2">
                            {calendar.selectedDay.prev_page_url && <Button asChild variant="outline" className="min-h-11"><Link href={calendar.selectedDay.prev_page_url}>Previous events</Link></Button>}
                            {calendar.selectedDay.next_page_url && <Button asChild variant="outline" className="min-h-11"><Link href={calendar.selectedDay.next_page_url}>More events</Link></Button>}
                        </div>
                    </nav>}
                </section>
            </main>
        </AppLayout>
    );
}
