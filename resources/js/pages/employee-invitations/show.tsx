import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface InvitationDetails {
    company: string;
    employee: string;
    email: string;
    expiresAt: string;
}

interface InvitationProps {
    state: 'pending' | 'invalid' | 'expired' | 'revoked' | 'accepted' | 'unavailable';
    invitation: InvitationDetails | null;
    accountState: 'none' | 'new_account' | 'sign_out_required' | 'existing_account_authenticated' | 'existing_account_sign_in' | 'wrong_account';
    acceptUrl: string | null;
    loginUrl: string;
    logoutUrl: string;
}

interface ActivationForm {
    [key: string]: string;
    password: string;
    password_confirmation: string;
}

export default function ShowEmployeeInvitation({ state, invitation, accountState, acceptUrl, loginUrl, logoutUrl }: InvitationProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ActivationForm>({
        password: '',
        password_confirmation: '',
    });
    const invitationError = (errors as Record<string, string | undefined>).invitation;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (acceptUrl) {
            post(acceptUrl, { onFinish: () => reset('password', 'password_confirmation') });
        }
    };

    if (state !== 'pending' || invitation === null) {
        const statusMessages: Record<Exclude<InvitationProps['state'], 'pending'>, string> = {
            invalid: 'This invitation link is not valid.',
            expired: 'This invitation has expired. Please ask your HR team to send a new one.',
            revoked: 'This invitation is no longer active. Please ask your HR team for a new invitation.',
            accepted: 'This invitation has already been used. Sign in to continue.',
            unavailable: 'This invitation is unavailable. Please contact your HR team for help.',
        };
        const message = statusMessages[state as keyof typeof statusMessages] ?? 'This invitation is unavailable.';

        return (
            <AuthLayout title="Employee invitation" description={message}>
                <Head title="Employee invitation" />
                <p className="text-muted-foreground text-center text-sm">If you already have an account, sign in to continue.</p>
                <Button asChild className="w-full">
                    <Link href={loginUrl}>Sign in</Link>
                </Button>
            </AuthLayout>
        );
    }

    const isNewAccount = accountState === 'new_account';
    const isExistingAccount = accountState === 'existing_account_authenticated';
    const requiresSignOut = accountState === 'sign_out_required' || accountState === 'wrong_account';

    return (
        <AuthLayout title={`You're invited to join ${invitation.company}`} description="Activate your employee account to get started.">
            <Head title="Accept employee invitation" />

            <div className="rounded-lg border bg-card p-4 text-sm">
                <p className="font-medium text-foreground">{invitation.employee}</p>
                <p className="mt-1 break-all text-muted-foreground">{invitation.email}</p>
                <p className="mt-3 text-muted-foreground">
                    Invitation expires {new Date(invitation.expiresAt).toLocaleString()}.
                </p>
            </div>

            <InputError message={invitationError} />

            {isNewAccount && (
                <form className="flex flex-col gap-5" onSubmit={submit}>
                    <p className="text-sm text-muted-foreground">Create a password for your account.</p>
                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            disabled={processing}
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                            disabled={processing}
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>
                    <Button type="submit" className="w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Activate account
                    </Button>
                </form>
            )}

            {accountState === 'existing_account_sign_in' && (
                <div className="grid gap-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        An account already exists for this email. Sign in with that account to continue.
                    </p>
                    <Button asChild className="w-full">
                        <Link href={loginUrl}>Sign in</Link>
                    </Button>
                </div>
            )}

            {isExistingAccount && (
                <form className="grid gap-4" onSubmit={submit}>
                    <p className="text-sm text-muted-foreground">You are signed in with the invited account. Continue to activate employee access.</p>
                    <Button type="submit" className="w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Continue
                    </Button>
                </form>
            )}

            {requiresSignOut && (
                <div className="grid gap-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        Sign out, then open this invitation and sign in with the invited email address to continue.
                    </p>
                    <Button type="button" variant="outline" className="w-full" disabled={processing} onClick={() => post(logoutUrl)}>
                        Sign out
                    </Button>
                </div>
            )}
        </AuthLayout>
    );
}
