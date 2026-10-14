import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import type { FormEventHandler } from 'react';

interface LeaveTypeOption {
    id: number;
    name: string;
    allowsHalfDay: boolean;
    reasonRequired: boolean;
    configured: boolean;
    available: string | null;
    pending: string | null;
}

interface EmployeeLeaveApplicationPageProps {
    asOfDate: string;
    leaveTypes: LeaveTypeOption[];
    canAccessManagement: boolean;
    companyName: string;
    employeeName: string;
}

interface LeaveApplicationForm {
    [key: string]: string;
    leave_type_id: string;
    start_date: string;
    end_date: string;
    duration: 'full_day' | 'half_day';
    reason: string;
}

function formatAmount(value: string): string {
    return value.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
}

function amountWithUnit(value: string): string {
    const amount = formatAmount(value);

    return `${amount} ${amount === '1' || amount === '-1' ? 'day' : 'days'}`;
}

export default function EmployeeLeaveApplyPage({
    asOfDate,
    leaveTypes,
    canAccessManagement,
    companyName,
    employeeName,
}: EmployeeLeaveApplicationPageProps) {
    const form = useForm<LeaveApplicationForm>({
        leave_type_id: leaveTypes[0] ? String(leaveTypes[0].id) : '',
        start_date: asOfDate,
        end_date: asOfDate,
        duration: 'full_day',
        reason: '',
    });
    const selectedType = leaveTypes.find((leaveType) => String(leaveType.id) === form.data.leave_type_id);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('employee.leave.store'), { preserveScroll: true });
    };

    return (
        <EmployeePortalLayout
            title="Apply for Leave"
            canAccessManagement={canAccessManagement}
            companyName={companyName}
            employeeName={employeeName}
        >
            <Head title="Apply for Leave" />
            <header className="grid min-w-0 gap-2">
                <Link
                    href={route('employee.leave.index')}
                    className="text-primary focus-visible:ring-ring w-fit text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    Back to My Leave
                </Link>
                <h1 className="break-words text-3xl font-semibold tracking-tight">Apply for Leave</h1>
                <p className="text-sm text-muted-foreground">
                    Choose the dates you need. Your leave days and balance will be checked using your current work schedule and policy.
                </p>
            </header>

            {leaveTypes.length === 0 ? (
                <Card className="min-w-0">
                    <CardContent className="grid gap-4 p-4 sm:p-6">
                        <p className="text-sm text-muted-foreground">No leave policy is currently assigned to you. Please contact HR.</p>
                        <Link
                            href={route('employee.leave.index')}
                            className="text-primary focus-visible:ring-ring w-fit text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            Return to My Leave
                        </Link>
                    </CardContent>
                </Card>
            ) : (
                <Card className="min-w-0">
                    <CardHeader className="p-4 sm:p-6">
                        <h2 className="text-lg font-semibold">Leave request details</h2>
                        <CardDescription>Leave is submitted to your HR team for review.</CardDescription>
                    </CardHeader>
                    <CardContent className="p-4 pt-0 sm:p-6 sm:pt-0">
                        <form onSubmit={submit} className="grid min-w-0 gap-5">
                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="leave_type_id">Leave type</Label>
                                <select
                                    id="leave_type_id"
                                    required
                                    value={form.data.leave_type_id}
                                    onChange={(event) => form.setData('leave_type_id', event.currentTarget.value)}
                                    disabled={form.processing}
                                    className="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    {leaveTypes.map((leaveType) => (
                                        <option key={leaveType.id} value={leaveType.id}>
                                            {leaveType.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.leave_type_id} />
                            </div>

                            {selectedType && (
                                <div className="grid min-w-0 gap-2 rounded-lg border bg-muted/30 p-3 text-sm sm:grid-cols-2 sm:p-4">
                                    <div className="grid min-w-0 gap-1">
                                        <span className="text-muted-foreground">Available as of {asOfDate}</span>
                                        <span className="break-words font-medium">
                                            {selectedType.configured && selectedType.available !== null
                                                ? amountWithUnit(selectedType.available)
                                                : 'Not currently available'}
                                        </span>
                                    </div>
                                    <div className="grid min-w-0 gap-1">
                                        <span className="text-muted-foreground">Pending requests</span>
                                        <span className="break-words font-medium">
                                            {selectedType.configured && selectedType.pending !== null
                                                ? amountWithUnit(selectedType.pending)
                                                : 'Not available'}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground sm:col-span-2">
                                        Available already accounts for pending requests. The balance shown here is informational; it is checked again when you submit.
                                    </p>
                                </div>
                            )}

                            <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                                <div className="grid min-w-0 gap-2">
                                    <Label htmlFor="start_date">From</Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        required
                                        className="min-h-11 min-w-0"
                                        value={form.data.start_date}
                                        onChange={(event) => form.setData('start_date', event.currentTarget.value)}
                                        disabled={form.processing}
                                    />
                                    <InputError message={form.errors.start_date} />
                                </div>
                                <div className="grid min-w-0 gap-2">
                                    <Label htmlFor="end_date">To</Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        required
                                        className="min-h-11 min-w-0"
                                        value={form.data.end_date}
                                        onChange={(event) => form.setData('end_date', event.currentTarget.value)}
                                        disabled={form.processing}
                                    />
                                    <InputError message={form.errors.end_date} />
                                </div>
                            </div>

                            {selectedType?.allowsHalfDay && (
                                <div className="grid min-w-0 gap-2">
                                    <Label htmlFor="duration">Request portion</Label>
                                    <select
                                        id="duration"
                                        value={form.data.duration}
                                        onChange={(event) => form.setData('duration', event.currentTarget.value as LeaveApplicationForm['duration'])}
                                        disabled={form.processing}
                                        className="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                    >
                                        <option value="full_day">Full day</option>
                                        <option value="half_day">Half day</option>
                                    </select>
                                    <p className="text-sm text-muted-foreground">Your policy and schedule determine the final chargeable days.</p>
                                    <InputError message={form.errors.duration} />
                                </div>
                            )}

                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="reason">Reason {selectedType?.reasonRequired ? '(required)' : '(optional)'}</Label>
                                <textarea
                                    id="reason"
                                    rows={4}
                                    maxLength={2000}
                                    minLength={3}
                                    required={selectedType?.reasonRequired ?? false}
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.currentTarget.value)}
                                    disabled={form.processing}
                                    className="border-input bg-background focus-visible:ring-ring min-h-28 w-full min-w-0 resize-y rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <InputError message={form.errors.reason} />
                                <p className="text-sm text-muted-foreground">Keep your reason brief. Maximum 2,000 characters.</p>
                            </div>

                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <Button type="submit" disabled={form.processing} className="min-h-11 w-full sm:w-auto">
                                    {form.processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                                    {form.processing ? 'Submitting request…' : 'Submit Leave Request'}
                                </Button>
                                <Link
                                    href={route('employee.leave.index')}
                                    className="text-muted-foreground focus-visible:ring-ring inline-flex min-h-11 items-center justify-center rounded-md px-4 text-sm font-medium hover:text-foreground focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    Cancel
                                </Link>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}
        </EmployeePortalLayout>
    );
}
