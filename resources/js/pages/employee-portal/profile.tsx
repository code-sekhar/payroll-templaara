import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import EmployeePortalLayout from '@/layouts/employee-portal-layout';
import { Head } from '@inertiajs/react';

interface EmployeeProfile {
    identity: {
        name: string;
        employeeCode: string;
    };
    personal: {
        email: string | null;
        phone: string | null;
    };
    employment: {
        department: string | null;
        designation: string | null;
        branch: string | null;
        type: string;
        status: string;
        joiningDate: string | null;
        manager: string | null;
    };
    workAssignment: {
        shift: string | null;
        workSchedule: string | null;
    };
    account: {
        loginEmail: string | null;
        connected: boolean;
    };
}

interface EmployeeProfilePageProps {
    employeeProfile: EmployeeProfile;
    canAccessManagement: boolean;
    companyName: string;
}

function formatDateOnly(value: string | null): string {
    if (!value) {
        return 'Not provided';
    }

    const [year, month, day] = value.split('-').map(Number);
    const localDate = new Date(year, month - 1, day);

    return new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(localDate);
}

function ProfileField({ label, value, fallback = 'Not provided' }: { label: string; value: string | null; fallback?: string }) {
    return (
        <div className="grid gap-1">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="break-words text-sm font-medium">{value || fallback}</dd>
        </div>
    );
}

export default function EmployeeProfilePage({ employeeProfile, canAccessManagement, companyName }: EmployeeProfilePageProps) {
    const { identity, personal, employment, workAssignment, account } = employeeProfile;

    return (
        <EmployeePortalLayout
            title="My Profile"
            canAccessManagement={canAccessManagement}
            companyName={companyName}
            employeeName={identity.name}
        >
            <Head title="My Profile" />
            <header className="grid gap-2">
                <p className="text-sm font-medium text-muted-foreground">Your information</p>
                <h1 className="text-3xl font-semibold tracking-tight">My Profile</h1>
                <p className="text-sm text-muted-foreground">View your personal and employment information on record with your company.</p>
            </header>

            <Card className="min-w-0">
                <CardHeader className="min-w-0 gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                    <div className="grid min-w-0 gap-1.5">
                        <h2 className="break-words text-2xl font-semibold leading-none tracking-tight">{identity.name}</h2>
                        <CardDescription className="break-words">
                            {[identity.employeeCode, employment.designation, employment.department].filter(Boolean).join(' · ')}
                        </CardDescription>
                    </div>
                    <Badge variant="outline" aria-label={`Employment status: ${employment.status}`}>{employment.status}</Badge>
                </CardHeader>
                <CardContent className="grid min-w-0 gap-6 p-4 pt-0 sm:gap-8 sm:p-6 sm:pt-0 md:grid-cols-2">
                    <section aria-labelledby="personal-information-heading" className="grid gap-5">
                        <h2 id="personal-information-heading" className="text-lg font-semibold">Personal information</h2>
                        <dl className="grid min-w-0 gap-4 sm:grid-cols-2">
                            <ProfileField label="Personal email" value={personal.email} />
                            <ProfileField label="Phone" value={personal.phone} />
                        </dl>
                    </section>

                    <section aria-labelledby="employment-heading" className="grid gap-5">
                        <h2 id="employment-heading" className="text-lg font-semibold">Employment</h2>
                        <dl className="grid min-w-0 gap-4 sm:grid-cols-2">
                            <ProfileField label="Employee code" value={identity.employeeCode} />
                            <ProfileField label="Department" value={employment.department} fallback="Not assigned" />
                            <ProfileField label="Designation" value={employment.designation} fallback="Not assigned" />
                            <ProfileField label="Branch" value={employment.branch} fallback="Not assigned" />
                            <ProfileField label="Employment type" value={employment.type} />
                            <ProfileField label="Joining date" value={employment.joiningDate ? formatDateOnly(employment.joiningDate) : null} />
                            <ProfileField label="Reports to" value={employment.manager} fallback="Not assigned" />
                        </dl>
                    </section>

                    <section aria-labelledby="work-assignment-heading" className="grid gap-5">
                        <h2 id="work-assignment-heading" className="text-lg font-semibold">Work assignment</h2>
                        <dl className="grid min-w-0 gap-4 sm:grid-cols-2">
                            <ProfileField label="Current shift" value={workAssignment.shift} fallback="Not assigned" />
                            <ProfileField label="Work schedule" value={workAssignment.workSchedule} fallback="Not assigned" />
                        </dl>
                    </section>

                    <section aria-labelledby="account-heading" className="grid gap-5">
                        <h2 id="account-heading" className="text-lg font-semibold">Account</h2>
                        <dl className="grid min-w-0 gap-4 sm:grid-cols-2">
                            <ProfileField label="Login email" value={account.loginEmail} fallback="No login account connected" />
                            <ProfileField label="Account status" value={account.connected ? 'Connected' : 'Not connected'} />
                        </dl>
                    </section>
                </CardContent>
            </Card>
        </EmployeePortalLayout>
    );
}
