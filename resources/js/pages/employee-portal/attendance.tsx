import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface AttendanceDay {
    date: string;
    dateLabel: string;
    weekday: string;
    isToday: boolean;
    isFuture: boolean;
    withinEmploymentPeriod: boolean;
    recorded: boolean;
    attendanceStatus: string | null;
    statusLabel: string | null;
    scheduledWork: boolean;
    shiftName: string | null;
    scheduledStart: string | null;
    scheduledEnd: string | null;
    checkIn: string | null;
    checkOut: string | null;
    workedDuration: string | null;
    holiday: boolean;
    holidayName: string | null;
    approvedLeave: boolean;
    leaveType: string | null;
    exceptions: string[];
    correctionPending: boolean;
}

interface CorrectionItem {
    action: string;
    punchType: string | null;
    originalTime: string | null;
    requestedTime: string | null;
}

interface CorrectionRequest {
    date: string;
    dateLabel: string;
    type: string;
    typeLabel: string;
    status: string;
    statusLabel: string;
    reason: string;
    requestedAt: string;
    canCancel: boolean;
    cancelReference: string | null;
    items: CorrectionItem[];
}

interface EmployeeAttendancePageProps {
    employeeAttendance: {
        month: string;
        monthLabel: string;
        timezone: string;
        today: string;
        recordedDays: number;
        previousMonth: string | null;
        nextMonth: string | null;
        days: AttendanceDay[];
    };
    employeePortal: {
        companyName: string;
        employeeName: string;
        canAccessManagement: boolean;
    };
    canRequestCorrection: boolean;
    canViewCorrections: boolean;
    correctionRequests: CorrectionRequest[];
}

function attendanceStatusVariant(status: string | null): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (status === 'present') {
        return 'default';
    }

    if (status === 'absent' || status === 'incomplete') {
        return 'destructive';
    }

    return 'secondary';
}

function MonthLink({ month, label }: { month: string | null; label: string }) {
    if (!month) {
        return (
            <span className="text-muted-foreground min-h-11 rounded-md px-3 py-2 text-sm" aria-disabled="true">
                {label}
            </span>
        );
    }

    return (
        <Link
            href={route('employee.attendance.index', { month })}
            className="bg-background hover:bg-accent focus-visible:ring-ring inline-flex min-h-11 items-center justify-center rounded-md border px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
        >
            {label}
        </Link>
    );
}

export default function EmployeeAttendancePage({
    employeeAttendance,
    employeePortal,
    canRequestCorrection,
    canViewCorrections,
    correctionRequests,
}: EmployeeAttendancePageProps) {
    const [cancelling, setCancelling] = useState(false);

    return (
        <EmployeePortalLayout
            title="My Attendance"
            canAccessManagement={employeePortal.canAccessManagement}
            companyName={employeePortal.companyName}
            employeeName={employeePortal.employeeName}
        >
            <header className="grid min-w-0 gap-2">
                <p className="text-muted-foreground text-sm font-medium">Your attendance record</p>
                <h1 className="text-3xl font-semibold tracking-tight break-words">My Attendance</h1>
                <p className="text-muted-foreground text-sm break-words">Saved daily attendance records for your employee profile.</p>
            </header>

            <section
                aria-label="Select attendance month"
                className="bg-card flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-xl border p-3 sm:p-4"
            >
                <MonthLink month={employeeAttendance.previousMonth} label="Previous month" />
                <h2 className="min-w-0 flex-1 text-center text-lg font-semibold break-words sm:text-xl" aria-live="polite">
                    {employeeAttendance.monthLabel}
                </h2>
                <MonthLink month={employeeAttendance.nextMonth} label="Next month" />
            </section>

            <p className="text-muted-foreground text-sm" aria-label={`${employeeAttendance.recordedDays} recorded attendance days`}>
                {employeeAttendance.recordedDays} {employeeAttendance.recordedDays === 1 ? 'day' : 'days'} with saved attendance records
            </p>

            <section aria-label={`Attendance for ${employeeAttendance.monthLabel}`} className="grid min-w-0 gap-3">
                {employeeAttendance.days.map((day) => (
                    <Card key={day.date} className={`min-w-0 ${day.isToday ? 'border-primary/60 ring-primary/20 ring-1' : ''}`}>
                        <CardHeader className="flex min-w-0 flex-row flex-wrap items-start justify-between gap-3 p-4 sm:p-5">
                            <div className="grid min-w-0 gap-1">
                                <p className="text-muted-foreground text-sm">
                                    {day.weekday}
                                    {day.isToday ? ' · Today' : ''}
                                </p>
                                <h3 className="text-lg font-semibold break-words">{day.dateLabel}</h3>
                            </div>
                            {day.statusLabel ? (
                                <Badge
                                    variant={attendanceStatusVariant(day.attendanceStatus)}
                                    className="max-w-full text-left break-words whitespace-normal"
                                >
                                    {day.statusLabel}
                                </Badge>
                            ) : (
                                <Badge variant="outline">{day.isFuture ? 'Upcoming' : day.isToday ? 'Not recorded yet' : 'Not recorded'}</Badge>
                            )}
                        </CardHeader>
                        <CardContent className="grid min-w-0 gap-4 p-4 pt-0 text-sm sm:grid-cols-2 sm:p-5 sm:pt-0 lg:grid-cols-4">
                            {day.isFuture && !day.recorded ? (
                                <p className="text-muted-foreground break-words sm:col-span-2 lg:col-span-4">No attendance record is expected yet.</p>
                            ) : !day.recorded ? (
                                <p className="text-muted-foreground break-words sm:col-span-2 lg:col-span-4">
                                    No saved attendance record for this date. This does not mean you were absent.
                                </p>
                            ) : (
                                <>
                                    {day.scheduledWork && (
                                        <div className="min-w-0">
                                            <p className="text-muted-foreground">Scheduled</p>
                                            <p className="font-medium break-words">{day.shiftName ?? 'Working day'}</p>
                                            {(day.scheduledStart || day.scheduledEnd) && (
                                                <p className="text-muted-foreground break-words">
                                                    {day.scheduledStart ?? '—'} – {day.scheduledEnd ?? '—'}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    <div className="min-w-0">
                                        <p className="text-muted-foreground">Check in</p>
                                        <p className="font-medium break-words">{day.checkIn ?? 'Not recorded'}</p>
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-muted-foreground">Check out</p>
                                        <p className="font-medium break-words">{day.checkOut ?? 'Not recorded'}</p>
                                    </div>
                                    {day.workedDuration && (
                                        <div className="min-w-0">
                                            <p className="text-muted-foreground">Recorded work duration</p>
                                            <p className="font-medium break-words">{day.workedDuration}</p>
                                        </div>
                                    )}
                                    {day.holiday && (
                                        <p className="text-muted-foreground break-words sm:col-span-2 lg:col-span-4">
                                            Holiday{day.holidayName ? ` · ${day.holidayName}` : ''}
                                        </p>
                                    )}
                                    {day.approvedLeave && (
                                        <p className="text-muted-foreground break-words sm:col-span-2 lg:col-span-4">
                                            Approved leave{day.leaveType ? ` · ${day.leaveType}` : ''}
                                        </p>
                                    )}
                                    {day.exceptions.length > 0 && (
                                        <section aria-label="Attendance exceptions" className="grid min-w-0 gap-1 sm:col-span-2 lg:col-span-4">
                                            <h4 className="font-medium">Needs attention</h4>
                                            <ul className="grid min-w-0 gap-1">
                                                {day.exceptions.map((exception) => (
                                                    <li key={exception} className="text-muted-foreground break-words">{exception}</li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                    {!day.scheduledWork &&
                                        !day.checkIn &&
                                        !day.checkOut &&
                                        !day.workedDuration &&
                                        !day.holiday &&
                                        !day.approvedLeave &&
                                        day.exceptions.length === 0 && (
                                            <p className="text-muted-foreground break-words sm:col-span-2 lg:col-span-4">
                                                No additional attendance details.
                                            </p>
                                        )}
                                </>
                            )}
                            {day.correctionPending && (
                                <p className="font-medium break-words text-amber-700 sm:col-span-2 lg:col-span-4">
                                    A correction request is pending for this date.
                                </p>
                            )}
                            {canRequestCorrection && !day.isFuture && day.withinEmploymentPeriod && !day.correctionPending && (
                                <div className="sm:col-span-2 lg:col-span-4">
                                    <Link
                                        href={route('employee.attendance.corrections.create', { attendance_date: day.date })}
                                        className="hover:bg-accent focus-visible:ring-ring inline-flex min-h-11 items-center rounded-md border px-4 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-hidden"
                                    >
                                        Request a correction
                                    </Link>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </section>

            {canViewCorrections && (
                <section aria-labelledby="correction-history-heading" className="grid min-w-0 gap-3">
                    <header className="grid gap-1">
                        <h2 id="correction-history-heading" className="text-xl font-semibold">
                            Correction requests
                        </h2>
                        <p className="text-muted-foreground text-sm">Requests for {employeeAttendance.monthLabel}.</p>
                    </header>
                    {correctionRequests.length === 0 ? (
                        <p className="bg-card text-muted-foreground rounded-xl border p-4 text-sm">No correction requests for this month.</p>
                    ) : (
                        correctionRequests.map((correction, index) => (
                            <Card key={`${correction.date}-${correction.requestedAt}-${index}`} className="min-w-0">
                                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 p-4 sm:p-5">
                                    <div className="grid min-w-0 gap-1">
                                        <h3 className="font-semibold break-words">{correction.typeLabel}</h3>
                                        <p className="text-muted-foreground text-sm">Attendance date: {correction.dateLabel}</p>
                                    </div>
                                    <Badge
                                        variant={
                                            correction.status === 'rejected'
                                                ? 'destructive'
                                                : correction.status === 'approved'
                                                  ? 'default'
                                                  : 'secondary'
                                        }
                                    >
                                        {correction.statusLabel}
                                    </Badge>
                                </CardHeader>
                                <CardContent className="grid min-w-0 gap-3 p-4 pt-0 text-sm sm:p-5 sm:pt-0">
                                    <ul className="grid min-w-0 gap-1">
                                        {correction.items.map((item, itemIndex) => (
                                            <li key={`${item.action}-${itemIndex}`} className="text-muted-foreground break-words">
                                                {item.action}
                                                {item.punchType ? ` ${item.punchType}` : ''}
                                                {item.originalTime ? ` · Original: ${item.originalTime}` : ''}
                                                {item.requestedTime ? ` · Requested: ${item.requestedTime}` : ''}
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="break-words">
                                        <span className="font-medium">Reason:</span> {correction.reason}
                                    </p>
                                    <p className="text-muted-foreground text-xs">Submitted {correction.requestedAt}</p>
                                    {correction.canCancel && correction.cancelReference && (
                                        <button
                                            type="button"
                                            disabled={cancelling}
                                            onClick={() => {
                                                if (cancelling || !window.confirm('Cancel this pending correction request?')) return;
                                                setCancelling(true);
                                                router.post(
                                                    route('employee.attendance.corrections.cancel', {
                                                        attendanceDate: correction.date,
                                                        requestReference: correction.cancelReference,
                                                    }),
                                                    {},
                                                    {
                                                        onFinish: () => setCancelling(false),
                                                    },
                                                );
                                            }}
                                            className="hover:bg-accent focus-visible:ring-ring inline-flex min-h-11 w-fit items-center rounded-md border px-4 py-2 font-medium focus-visible:ring-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50"
                                        >
                                            {cancelling ? 'Cancelling…' : 'Cancel request'}
                                        </button>
                                    )}
                                </CardContent>
                            </Card>
                        ))
                    )}
                </section>
            )}

            <p className="text-muted-foreground text-xs break-words">Times are shown in {employeeAttendance.timezone}.</p>
        </EmployeePortalLayout>
    );
}
