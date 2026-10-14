import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type LeaveStatus = 'pending' | 'approved';

interface LeaveCalendarItem {
    leaveType: string;
    status: LeaveStatus;
    statusLabel: string;
    amount: string;
    dayType: string;
    href: string;
}

interface CalendarDay {
    date: string;
    leaveItems: LeaveCalendarItem[];
    holidayName: string | null;
    weeklyOff: boolean;
    scheduleAvailable: boolean;
}

interface EmployeeLeaveCalendar {
    month: string;
    monthLabel: string;
    previousMonth: string;
    nextMonth: string;
    today: string;
    timezone: string;
    weekdays: string[];
    days: CalendarDay[];
}

interface EmployeeLeaveCalendarPageProps {
    calendar: EmployeeLeaveCalendar;
    canAccessManagement: boolean;
    companyName: string;
    employeeName: string;
}

function dateLabel(value: string, options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' }): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}

function amountLabel(value: string): string {
    const amount = value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');

    return `${amount} ${amount === '1' || amount === '0.5' ? 'day' : 'days'} charged`;
}

function eventSummary(day: CalendarDay): string[] {
    return [
        ...day.leaveItems.map((item) => `${item.statusLabel} ${item.leaveType}`),
        ...(day.holidayName ? [`Holiday: ${day.holidayName}`] : []),
        ...(day.weeklyOff ? ['Weekly off'] : []),
    ];
}

export default function EmployeeLeaveCalendarPage({
    calendar,
    canAccessManagement,
    companyName,
    employeeName,
}: EmployeeLeaveCalendarPageProps) {
    const [selectedDate, setSelectedDate] = useState(() => {
        if (calendar.today.startsWith(calendar.month)) {
            return calendar.today;
        }

        return calendar.days.find((day) => eventSummary(day).length > 0)?.date ?? calendar.days[0]?.date ?? `${calendar.month}-01`;
    });

    useEffect(() => {
        const selectedStillInMonth = selectedDate.startsWith(calendar.month);
        if (!selectedStillInMonth) {
            setSelectedDate(calendar.today.startsWith(calendar.month)
                ? calendar.today
                : calendar.days.find((day) => eventSummary(day).length > 0)?.date ?? calendar.days[0]?.date ?? `${calendar.month}-01`);
        }
    }, [calendar, selectedDate]);

    const [year, monthNumber] = calendar.month.split('-').map(Number);
    const firstWeekday = (new Date(Date.UTC(year, monthNumber - 1, 1)).getUTCDay() + 6) % 7;
    const gridCells: (CalendarDay | null)[] = [
        ...Array.from({ length: firstWeekday }, () => null),
        ...calendar.days,
    ];
    const calendarRows = Array.from({ length: Math.ceil(gridCells.length / 7) }, (_, rowIndex) =>
        gridCells.slice(rowIndex * 7, rowIndex * 7 + 7),
    );
    const selectedDay = calendar.days.find((day) => day.date === selectedDate);
    const hasAnyEvents = calendar.days.some((day) => eventSummary(day).length > 0);

    return (
        <EmployeePortalLayout
            title="My Leave Calendar"
            canAccessManagement={canAccessManagement}
            companyName={companyName}
            employeeName={employeeName}
        >
            <Head title="My Leave Calendar" />
            <header className="grid min-w-0 gap-2">
                <div className="flex min-w-0 flex-wrap items-end justify-between gap-3">
                    <div className="grid min-w-0 gap-1">
                        <h1 className="break-words text-3xl font-semibold tracking-tight">My Leave Calendar</h1>
                        <p className="text-sm text-muted-foreground">Your approved and pending leave, company holidays, and scheduled days off.</p>
                    </div>
                    <Link
                        href={route('employee.leave.calendar')}
                        className="focus-visible:ring-ring inline-flex min-h-11 shrink-0 items-center rounded-md border border-input px-4 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        Today
                    </Link>
                </div>
            </header>

            <Card className="min-w-0">
                <CardHeader className="grid min-w-0 gap-3 p-4 sm:p-5">
                    <div className="flex min-w-0 items-center justify-between gap-2">
                        <Link
                            href={route('employee.leave.calendar', { month: calendar.previousMonth })}
                            aria-label="Previous month"
                            className="focus-visible:ring-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-input px-3 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <span aria-hidden="true">←</span><span className="sr-only">Previous month</span>
                        </Link>
                        <h2 className="min-w-0 break-words text-center text-xl font-semibold" aria-live="polite">{calendar.monthLabel}</h2>
                        <Link
                            href={route('employee.leave.calendar', { month: calendar.nextMonth })}
                            aria-label="Next month"
                            className="focus-visible:ring-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-input px-3 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <span aria-hidden="true">→</span><span className="sr-only">Next month</span>
                        </Link>
                    </div>
                </CardHeader>
                <CardContent className="grid min-w-0 gap-4 p-3 pt-0 sm:gap-5 sm:p-5 sm:pt-0">
                    <div role="grid" aria-label={`${calendar.monthLabel} leave calendar`} className="grid min-w-0 gap-1 sm:gap-2">
                        <div role="row" className="grid min-w-0 grid-cols-7 gap-1 sm:gap-2">
                            {calendar.weekdays.map((weekday) => (
                                <div role="columnheader" key={weekday} className="min-w-0 py-2 text-center text-[11px] font-medium text-muted-foreground sm:text-sm">
                                    <span className="hidden sm:inline">{weekday}</span>
                                    <span className="sm:hidden">{weekday.slice(0, 1)}</span>
                                </div>
                            ))}
                        </div>
                        {calendarRows.map((week, weekIndex) => (
                            <div role="row" key={`week-${weekIndex}`} className="grid min-w-0 grid-cols-7 gap-1 sm:gap-2">
                                {week.map((day, dayIndex) => {
                                    if (!day) {
                                        return <div role="gridcell" aria-hidden="true" key={`blank-${dayIndex}`} className="min-h-14 rounded-md sm:min-h-20" />;
                                    }

                                    const dateNumber = Number(day.date.slice(-2));
                                    const summaries = eventSummary(day);
                                    const markers = [
                                        ...day.leaveItems.map((item) => item.status === 'approved' ? 'A' : 'P'),
                                        ...(day.holidayName ? ['H'] : []),
                                        ...(day.weeklyOff ? ['O'] : []),
                                    ];

                                    return (
                                        <div role="gridcell" key={day.date} className="min-w-0">
                                            <button
                                                type="button"
                                                aria-label={`${dateLabel(day.date)}${summaries.length ? `: ${summaries.join(', ')}` : ''}`}
                                                aria-pressed={selectedDate === day.date}
                                                onClick={() => setSelectedDate(day.date)}
                                                className={`focus-visible:ring-ring flex min-h-14 w-full min-w-0 flex-col items-center gap-1 rounded-md border p-1 text-center text-sm focus-visible:z-10 focus-visible:ring-2 focus-visible:outline-hidden sm:min-h-20 sm:items-start sm:p-2 ${selectedDate === day.date ? 'border-primary bg-primary/5' : 'border-transparent hover:bg-muted/60'} ${calendar.today === day.date ? 'font-semibold' : ''}`}
                                            >
                                                <span className="tabular-nums">{dateNumber}</span>
                                                <span aria-hidden="true" className="flex min-h-4 max-w-full flex-wrap justify-center gap-0.5 sm:justify-start">
                                                    {markers.slice(0, 4).map((marker, index) => (
                                                        <span key={`${marker}-${index}`} className="inline-flex size-4 items-center justify-center rounded-full bg-muted text-[9px] leading-none text-foreground sm:size-5 sm:text-[10px]">
                                                            {marker}
                                                        </span>
                                                    ))}
                                                    {markers.length > 4 && <span className="text-[10px]">+{markers.length - 4}</span>}
                                                </span>
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>

                    <section aria-labelledby="calendar-legend-heading" className="grid min-w-0 gap-2 border-t pt-4">
                        <h3 id="calendar-legend-heading" className="text-sm font-semibold">Calendar legend</h3>
                        <ul className="flex min-w-0 flex-wrap gap-x-4 gap-y-2 text-xs sm:text-sm">
                            <li><span aria-hidden="true" className="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px]">A</span>Approved Leave</li>
                            <li><span aria-hidden="true" className="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px]">P</span>Pending Leave</li>
                            <li><span aria-hidden="true" className="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px]">H</span>Company Holiday</li>
                            <li><span aria-hidden="true" className="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px]">O</span>Weekly Off</li>
                        </ul>
                    </section>
                    <p className="text-xs text-muted-foreground">Dates and schedule context use {calendar.timezone}.</p>
                </CardContent>
            </Card>

            <section aria-labelledby="selected-day-heading" className="grid min-w-0 gap-3">
                <div className="grid min-w-0 gap-1">
                    <h2 id="selected-day-heading" className="break-words text-xl font-semibold tracking-tight">
                        {selectedDay ? dateLabel(selectedDay.date) : 'Selected day'}
                    </h2>
                    <p className="text-sm text-muted-foreground">Day details</p>
                </div>
                <Card className="min-w-0">
                    <CardContent className="grid min-w-0 gap-3 p-4 sm:p-5">
                        {selectedDay && (
                            <>
                                {selectedDay.leaveItems.map((item, index) => (
                                    <div key={`${item.href}-${index}`} className="grid min-w-0 gap-2 rounded-md border p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                        <div className="grid min-w-0 gap-1">
                                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                                <h3 className="min-w-0 break-words font-medium">{item.leaveType}</h3>
                                                <Badge variant="outline" className="max-w-full whitespace-normal">{item.statusLabel}</Badge>
                                            </div>
                                            <p className="break-words text-sm text-muted-foreground">{item.dayType} · {amountLabel(item.amount)}</p>
                                        </div>
                                        <Button variant="outline" className="min-h-11 w-full sm:w-auto" asChild>
                                            <Link href={item.href}>View request</Link>
                                        </Button>
                                    </div>
                                ))}
                                {selectedDay.holidayName && (
                                    <p className="break-words rounded-md border p-3 text-sm"><span className="font-medium">Company Holiday:</span> {selectedDay.holidayName}</p>
                                )}
                                {selectedDay.weeklyOff && <p className="rounded-md border p-3 text-sm">Weekly Off · Scheduled day off</p>}
                                {!selectedDay.scheduleAvailable && <p className="rounded-md border p-3 text-sm text-muted-foreground">No work schedule assignment is available for this date.</p>}
                                {selectedDay.leaveItems.length === 0 && !selectedDay.holidayName && !selectedDay.weeklyOff && (
                                    <p className="rounded-md border p-3 text-sm text-muted-foreground">
                                        {hasAnyEvents ? 'No leave or calendar events on this date.' : 'No leave or calendar events for this month.'}
                                    </p>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </section>
        </EmployeePortalLayout>
    );
}
