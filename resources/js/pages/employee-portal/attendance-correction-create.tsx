import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import type { FormEventHandler } from 'react';

interface CorrectionContext {
    date: string;
    timezone: string;
    record: { status: string; checkIn: string | null; checkOut: string | null } | null;
    punches: { reference: string; label: string; type: 'check_in' | 'check_out'; time: string }[];
}

interface EmployeePortalContext {
    companyName: string;
    employeeName: string;
    canAccessManagement: boolean;
}

interface CorrectionForm {
    [key: string]: string;
    attendance_date: string;
    request_type: string;
    reason: string;
    punch_reference: string;
    requested_check_in: string;
    requested_check_out: string;
}

interface PageProps {
    context: CorrectionContext;
    employeePortal: EmployeePortalContext;
}

const requestTypes = [
    { value: 'missing_check_in', label: 'Missing check-in' },
    { value: 'missing_check_out', label: 'Missing check-out' },
    { value: 'missing_punch_pair', label: 'Missing check-in and check-out' },
    { value: 'incorrect_punch', label: 'Incorrect punch time' },
    { value: 'duplicate_punch', label: 'Duplicate punch' },
];

export default function EmployeeAttendanceCorrectionCreate({ context, employeePortal }: PageProps) {
    const form = useForm<CorrectionForm>({
        attendance_date: context.date,
        request_type: 'missing_check_in',
        reason: '',
        punch_reference: '',
        requested_check_in: '',
        requested_check_out: '',
    });
    const selectedPunch = context.punches.find((punch) => punch.reference === form.data.punch_reference);
    const serverErrors = form.errors as Record<string, string | undefined>;
    const itemErrors = Object.entries(serverErrors)
        .filter(([key, message]) => key.startsWith('items.') && Boolean(message))
        .map(([, message]) => message as string);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => {
            let items: { action: string; punch_reference?: string; punch_type?: string; requested_punched_at?: string }[];
            if (data.request_type === 'missing_check_in') {
                items = [{ action: 'add', punch_type: 'check_in', requested_punched_at: data.requested_check_in }];
            } else if (data.request_type === 'missing_check_out') {
                items = [{ action: 'add', punch_type: 'check_out', requested_punched_at: data.requested_check_out }];
            } else if (data.request_type === 'missing_punch_pair') {
                items = [
                    { action: 'add', punch_type: 'check_in', requested_punched_at: data.requested_check_in },
                    { action: 'add', punch_type: 'check_out', requested_punched_at: data.requested_check_out },
                ];
            } else if (data.request_type === 'incorrect_punch' && selectedPunch) {
                const requestedAt = selectedPunch.type === 'check_in' ? data.requested_check_in : data.requested_check_out;
                items = [
                    { action: 'replace', punch_reference: data.punch_reference, punch_type: selectedPunch.type, requested_punched_at: requestedAt },
                ];
            } else {
                items = [{ action: 'remove', punch_reference: data.punch_reference }];
            }

            return {
                attendance_date: data.attendance_date,
                request_type: data.request_type,
                reason: data.reason,
                items,
            };
        });
        form.post(route('employee.attendance.corrections.store'));
    };

    const isPair = form.data.request_type === 'missing_punch_pair';
    const isIncorrect = form.data.request_type === 'incorrect_punch';
    const isDuplicate = form.data.request_type === 'duplicate_punch';
    const proposedPunchType = selectedPunch?.type;

    return (
        <EmployeePortalLayout
            title="Request attendance correction"
            canAccessManagement={employeePortal.canAccessManagement}
            companyName={employeePortal.companyName}
            employeeName={employeePortal.employeeName}
        >
            <Head title="Request attendance correction" />
            <header className="grid min-w-0 gap-2">
                <Link
                    href={route('employee.attendance.index', { month: context.date.slice(0, 7) })}
                    className="text-primary focus-visible:ring-ring w-fit text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Back to My Attendance
                </Link>
                <h1 className="text-3xl font-semibold tracking-tight break-words">Request an attendance correction</h1>
                <p className="text-muted-foreground text-sm">
                    For {context.date}. Times use {context.timezone}.
                </p>
            </header>

            <Card className="min-w-0">
                <CardHeader className="p-4 sm:p-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-lg font-semibold">Attendance on this date</h2>
                        {context.record && <Badge variant="outline">{context.record.status}</Badge>}
                    </div>
                    <CardDescription>
                        {context.record
                            ? `Check-in: ${context.record.checkIn ?? 'Not recorded'} · Check-out: ${context.record.checkOut ?? 'Not recorded'}`
                            : 'No saved daily attendance record exists for this date. You can still submit a request.'}
                    </CardDescription>
                </CardHeader>
            </Card>

            <Card className="min-w-0">
                <CardContent className="p-4 sm:p-6">
                    <form onSubmit={submit} className="grid min-w-0 gap-5">
                        <input type="hidden" name="attendance_date" value={form.data.attendance_date} />
                        <div className="grid min-w-0 gap-2">
                            <Label htmlFor="request_type">What needs correcting?</Label>
                            <select
                                id="request_type"
                                value={form.data.request_type}
                                onChange={(event) => {
                                    form.setData('request_type', event.target.value);
                                    form.setData('punch_reference', '');
                                }}
                                disabled={form.processing}
                                className="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                {requestTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.request_type} />
                        </div>

                        {(isIncorrect || isDuplicate) && (
                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="punch_reference">Choose the punch</Label>
                                <select
                                    id="punch_reference"
                                    required
                                    value={form.data.punch_reference}
                                    onChange={(event) => form.setData('punch_reference', event.target.value)}
                                    disabled={form.processing || context.punches.length === 0}
                                    className="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    <option value="">Select a punch</option>
                                    {context.punches.map((punch) => (
                                        <option key={punch.reference} value={punch.reference}>
                                            {punch.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={serverErrors['items.0.punch_reference']} />
                                {context.punches.length === 0 && (
                                    <p className="text-muted-foreground text-sm">There are no saved punches to select for this date.</p>
                                )}
                            </div>
                        )}

                        {(form.data.request_type === 'missing_check_in' || isPair || (isIncorrect && proposedPunchType === 'check_in')) && (
                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="requested_check_in">Requested check-in time</Label>
                                <Input
                                    id="requested_check_in"
                                    type="datetime-local"
                                    required
                                    className="min-h-11 min-w-0"
                                    value={form.data.requested_check_in}
                                    onChange={(event) => form.setData('requested_check_in', event.target.value)}
                                    disabled={form.processing}
                                />
                                <InputError message={serverErrors['items.0.requested_punched_at']} />
                            </div>
                        )}

                        {(form.data.request_type === 'missing_check_out' || isPair || (isIncorrect && proposedPunchType === 'check_out')) && (
                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="requested_check_out">Requested check-out time</Label>
                                <Input
                                    id="requested_check_out"
                                    type="datetime-local"
                                    required
                                    className="min-h-11 min-w-0"
                                    value={form.data.requested_check_out}
                                    onChange={(event) => form.setData('requested_check_out', event.target.value)}
                                    disabled={form.processing}
                                />
                                <InputError message={serverErrors['items.0.requested_punched_at']} />
                            </div>
                        )}

                        <div className="grid min-w-0 gap-2">
                            <Label htmlFor="reason">Reason</Label>
                            <textarea
                                id="reason"
                                required
                                minLength={10}
                                maxLength={5000}
                                rows={4}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                disabled={form.processing}
                                className="border-input bg-background focus-visible:ring-ring w-full min-w-0 resize-y rounded-md border px-3 py-2 text-sm break-words focus-visible:ring-2 focus-visible:outline-hidden"
                                placeholder="Tell us what happened."
                            />
                            <p className="text-muted-foreground text-xs">
                                Please enter at least 10 characters. Your attendance will not change unless HR approves the request.
                            </p>
                            <InputError message={form.errors.reason} />
                        </div>

                        <InputError message={form.errors.attendance_date} />
                        <InputError message={serverErrors.items} />
                        {itemErrors.length > 0 && (
                            <ul role="alert" className="text-destructive grid gap-1 text-sm">
                                {itemErrors.map((message, index) => (
                                    <li key={`${message}-${index}`}>{message}</li>
                                ))}
                            </ul>
                        )}
                        <div className="flex min-w-0 flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <Button variant="outline" type="button" asChild disabled={form.processing}>
                                <Link href={route('employee.attendance.index', { month: context.date.slice(0, 7) })}>Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || ((isIncorrect || isDuplicate) && !form.data.punch_reference)}
                                className="min-h-11"
                            >
                                {form.processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                                {form.processing ? 'Submitting request…' : 'Submit request'}
                            </Button>
                        </div>
                        <p className="sr-only" aria-live="polite">
                            {form.processing ? 'Submitting attendance correction request.' : ''}
                        </p>
                    </form>
                </CardContent>
            </Card>
        </EmployeePortalLayout>
    );
}
