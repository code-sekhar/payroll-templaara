import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { FormEvent, useState } from 'react';

type Employee = {
    id: number; employee_code: string; first_name: string; middle_name: string | null; last_name: string; date_of_birth: string | null; gender: string | null; marital_status: string | null; personal_email: string | null; personal_phone: string | null;
    joining_date: string; employment_type: string; employment_status: string; address: string | null; city: string | null; state: string | null; country: string | null; postal_code: string | null;
    emergency_contact_name: string | null; emergency_contact_phone: string | null; emergency_contact_relationship: string | null; notes: string | null;
    branch: { id: number; name: string } | null; department: { id: number; name: string } | null; designation: { id: number; name: string } | null;
    manager: { id: number; first_name: string; last_name: string } | null; user: { name: string; email: string } | null;
};

type LoginAccess = {
    status: 'not_invited' | 'pending' | 'expired' | 'active' | 'unavailable';
    email: string | null;
    invitationEmail: string | null;
    sentAt: string | null;
    expiresAt: string | null;
    emailChanged: boolean;
    canInvite: boolean;
    unavailableReason: 'employee_inactive' | 'email_missing' | 'email_invalid' | null;
};

export default function EmployeeShow({ employee, canUpdate, canDelete, canViewHolidayCalendarAssignments, holidayCalendarAssignment, holidayCalendarAssignmentsUrl, canViewLeavePolicyAssignments, leavePolicyAssignments = [], leavePolicyAssignmentsUrl, canViewLeaveBalances, leaveBalanceAccounts = [], leaveBalancesUrl, loginAccess, invitationUrl }: {
    employee: Employee;
    canUpdate: boolean;
    canDelete: boolean;
    canViewHolidayCalendarAssignments: boolean;
    holidayCalendarAssignment: { name: string | null; year: number | null; effective_from: string; effective_to: string | null } | null;
    holidayCalendarAssignmentsUrl: string | null;
    canViewLeavePolicyAssignments?: boolean;
    leavePolicyAssignments?: { leave_type: string; policy: string; effective_from: string; effective_to: string | null }[];
    leavePolicyAssignmentsUrl?: string | null;
    canViewLeaveBalances?: boolean;
    leaveBalanceAccounts?: { id: number; leave_type: string; period_start: string; period_end: string; balance: string }[];
    leaveBalancesUrl?: string | null;
    loginAccess: LoginAccess;
    invitationUrl: string | null;
}) {
    const fullName = [employee.first_name, employee.middle_name, employee.last_name].filter(Boolean).join(' ');
    const display = (value: string | null | undefined) => value || '—';
    const detail = (label: string, value: string | null | undefined) => <div className="grid gap-1"><dt className="text-xs text-muted-foreground">{label}</dt><dd className="text-sm">{display(value)}</dd></div>;
    const section = (title: string, children: ReactNode) => <section className="rounded-xl border p-5"><h2 className="mb-5 text-lg font-medium">{title}</h2><dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{children}</dl></section>;
    const invitation = useForm({});
    const [resendOpen, setResendOpen] = useState(false);
    const { flash } = usePage<SharedData>().props;
    const invite = (event?: FormEvent) => {
        event?.preventDefault();
        if (!invitationUrl || invitation.processing) return;
        invitation.post(invitationUrl, {
            preserveScroll: true,
            onSuccess: () => setResendOpen(false),
        });
    };
    const dateTime = (value: string | null) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
    const loginStatus = {
        not_invited: 'Not invited',
        pending: 'Invitation pending',
        expired: 'Invitation expired',
        active: 'Active',
        unavailable: 'Unavailable',
    }[loginAccess.status];
    const statusClass = loginAccess.status === 'active'
        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100'
        : loginAccess.status === 'pending'
            ? 'border-blue-300 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100'
            : loginAccess.status === 'unavailable'
                ? 'border-muted bg-muted text-muted-foreground'
                : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100';
    const actionLabel = loginAccess.status === 'pending' && !loginAccess.emailChanged ? 'Resend Invitation' : loginAccess.status === 'not_invited' ? 'Invite Employee' : 'Send New Invitation';
    const archive = () => {
        if (window.confirm(`Archive ${fullName}?`)) {
            router.delete(`/employees/${employee.id}`);
        }
    };
    return <AppLayout breadcrumbs={[{ title: 'Employees', href: '/employees' }, { title: fullName, href: `/employees/${employee.id}` }]}>
            <Head title={fullName} />
            <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                {flash?.success && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">{flash.success}</p>}
                {flash?.warning && <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">{flash.warning}</p>}
                <header className="flex flex-wrap items-center justify-between gap-4"><div className="flex items-center gap-4"><div className="flex size-14 items-center justify-center rounded-full bg-muted text-lg font-semibold">{employee.first_name.slice(0, 1)}{employee.last_name.slice(0, 1)}</div><div><h1 className="text-2xl font-semibold">{fullName}</h1><p className="mt-1 text-sm text-muted-foreground">{employee.employee_code} · <span className="capitalize">{employee.employment_status.replaceAll('_', ' ')}</span></p></div></div><div className="flex gap-2">{canUpdate && <Link className="rounded-md border px-4 py-2 text-sm" href={`/employees/${employee.id}/edit`}>Edit profile</Link>}{canDelete && <button type="button" onClick={archive} className="rounded-md border border-destructive/50 px-4 py-2 text-sm text-destructive">Archive</button>}</div></header>
                {section('Personal information', <>{detail('Date of birth', employee.date_of_birth)}{detail('Gender', employee.gender)}{detail('Marital status', employee.marital_status)}{detail('Personal email', employee.personal_email)}{detail('Personal phone', employee.personal_phone)}{detail('Login account', employee.user ? `${employee.user.name} (${employee.user.email})` : 'No login account')}</>)}
                <section className="grid gap-5 rounded-xl border p-5 sm:grid-cols-[1fr_auto] sm:items-center">
                    <div className="grid gap-4">
                        <div className="flex flex-wrap items-center gap-3"><h2 className="text-lg font-medium">Login Access</h2><Badge variant="outline" className={statusClass}>{loginStatus}</Badge></div>
                        <dl className="grid gap-4 text-sm sm:grid-cols-2">
                            <div><dt className="text-xs text-muted-foreground">Email</dt><dd>{loginAccess.email ?? 'No personal email added'}</dd></div>
                            {loginAccess.status === 'pending' && <>
                                <div><dt className="text-xs text-muted-foreground">Sent</dt><dd>{dateTime(loginAccess.sentAt)}</dd></div>
                                <div><dt className="text-xs text-muted-foreground">Expires</dt><dd>{dateTime(loginAccess.expiresAt)}</dd></div>
                                {loginAccess.emailChanged && <div className="sm:col-span-2" role="status"><p className="font-medium">Invitation pending for {loginAccess.invitationEmail}.</p><p className="text-muted-foreground">The current personal email is different. Send a new invitation to use the updated address.</p></div>}
                            </>}
                            {loginAccess.status === 'active' && <div><dt className="text-xs text-muted-foreground">Account</dt><dd>Account linked successfully</dd></div>}
                            {loginAccess.status === 'unavailable' && <div className="sm:col-span-2 text-muted-foreground">
                                {loginAccess.unavailableReason === 'email_missing' && 'Add a personal email to invite this employee.'}
                                {loginAccess.unavailableReason === 'email_invalid' && 'Add a valid personal email to invite this employee.'}
                                {loginAccess.unavailableReason === 'employee_inactive' && 'Login invitations are unavailable for inactive employees.'}
                            </div>}
                        </dl>
                    </div>
                    {loginAccess.canInvite && invitationUrl && loginAccess.status !== 'active' && loginAccess.status !== 'unavailable' && (
                        loginAccess.status === 'pending'
                            ? <Dialog open={resendOpen} onOpenChange={setResendOpen}>
                                <Button type="button" variant="outline" className="w-full sm:w-auto" disabled={invitation.processing} onClick={() => setResendOpen(true)}>{invitation.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />} {actionLabel}</Button>
                                <DialogContent>
                                    <DialogTitle>Send a new invitation?</DialogTitle>
                                    <DialogDescription>The previous invitation link will stop working. A new link will be sent to {loginAccess.emailChanged ? loginAccess.email : loginAccess.invitationEmail}.</DialogDescription>
                                    <DialogFooter>
                                        <DialogClose asChild><Button type="button" variant="outline" disabled={invitation.processing}>Cancel</Button></DialogClose>
                                        <Button type="button" disabled={invitation.processing} onClick={() => invite()}>{invitation.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />} {invitation.processing ? 'Sending…' : 'Send new invitation'}</Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                            : <Button type="button" className="w-full sm:w-auto" disabled={invitation.processing} onClick={() => invite()}>{invitation.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />} {invitation.processing ? 'Sending…' : actionLabel}</Button>
                    )}
                </section>
            {section('Employment information', <>{detail('Employee code', employee.employee_code)}{detail('Joining date', employee.joining_date)}{detail('Employment type', employee.employment_type.replaceAll('_', ' '))}{detail('Employment status', employee.employment_status.replaceAll('_', ' '))}{detail('Manager', employee.manager ? `${employee.manager.first_name} ${employee.manager.last_name}` : null)}</>)}
            {section('Organization', <>{detail('Branch', employee.branch?.name)}{detail('Department', employee.department?.name)}{detail('Designation', employee.designation?.name)}</>)}
            {canViewHolidayCalendarAssignments && <section className="rounded-xl border p-5"><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-medium">Current holiday calendar</h2>{holidayCalendarAssignmentsUrl && <Link className="text-sm underline underline-offset-4" href={`${holidayCalendarAssignmentsUrl}?search=${encodeURIComponent(employee.employee_code)}`}>Assignment history</Link>}</div>{holidayCalendarAssignment ? <p className="text-sm">{holidayCalendarAssignment.name ?? 'Archived calendar'}{holidayCalendarAssignment.year ? ` · ${holidayCalendarAssignment.year}` : ''}<span className="block text-muted-foreground">Effective {new Date(`${holidayCalendarAssignment.effective_from}T00:00:00`).toLocaleDateString()} → {holidayCalendarAssignment.effective_to ? new Date(`${holidayCalendarAssignment.effective_to}T00:00:00`).toLocaleDateString() : 'Present'}</span></p> : <p className="text-sm text-muted-foreground">No active holiday calendar assignment applies today.</p>}</section>}
            {canViewLeavePolicyAssignments && <section className="grid gap-4 rounded-xl border p-5"><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-medium">Current leave policies</h2>{leavePolicyAssignmentsUrl && <Link className="text-sm underline underline-offset-4" href={`${leavePolicyAssignmentsUrl}?search=${encodeURIComponent(employee.employee_code)}`}>Assignment history</Link>}</div>{leavePolicyAssignments.length ? <div className="grid gap-3 sm:grid-cols-2">{leavePolicyAssignments.map((assignment, index) => <div key={`${assignment.leave_type}-${index}`} className="rounded-lg bg-muted/40 p-3 text-sm"><p className="font-medium">{assignment.leave_type}</p><p>{assignment.policy}</p><p className="text-xs text-muted-foreground">Effective {new Date(`${assignment.effective_from}T00:00:00`).toLocaleDateString()} → {assignment.effective_to ? new Date(`${assignment.effective_to}T00:00:00`).toLocaleDateString() : 'Present'}</p></div>)}</div> : <p className="text-sm text-muted-foreground">No active leave policy assignments apply today.</p>}</section>}
            {canViewLeaveBalances && <section className="grid gap-4 rounded-xl border p-5"><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-medium">Current leave balances</h2>{leaveBalancesUrl && <Link className="text-sm underline underline-offset-4" href={`${leaveBalancesUrl}?search=${encodeURIComponent(employee.employee_code)}`}>Account history</Link>}</div>{leaveBalanceAccounts.length ? <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{leaveBalanceAccounts.map((account) => <Link key={account.id} href={leaveBalancesUrl ? `${leaveBalancesUrl}/${account.id}` : '/employees'} className="rounded-lg bg-muted/40 p-3 text-sm hover:bg-muted"><p className="font-medium">{account.leave_type}</p><p className="mt-1">{account.balance} days</p><p className="text-xs text-muted-foreground">{new Date(`${account.period_start}T00:00:00`).toLocaleDateString()} – {new Date(`${account.period_end}T00:00:00`).toLocaleDateString()}</p></Link>)}</div> : <p className="text-sm text-muted-foreground">No current open leave balance accounts.</p>}</section>}
            {section('Contact information', <>{detail('Address', employee.address)}{detail('City', employee.city)}{detail('State', employee.state)}{detail('Country', employee.country)}{detail('Postal code', employee.postal_code)}</>)}
            {section('Emergency contact', <>{detail('Name', employee.emergency_contact_name)}{detail('Phone', employee.emergency_contact_phone)}{detail('Relationship', employee.emergency_contact_relationship)}{detail('Notes', employee.notes)}</>)}
        </main>
    </AppLayout>;
}
