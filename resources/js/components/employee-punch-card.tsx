import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { router } from '@inertiajs/react';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface EmployeePunchState {
    state: 'not_started' | 'clocked_in' | 'clocked_out';
    canPunchIn: boolean;
    canPunchOut: boolean;
    reasonCode: 'ALREADY_PUNCHED_IN' | 'ALREADY_PUNCHED_OUT' | 'LOCATION_NOT_CONFIGURED' | null;
    lastPunchType: 'check_in' | 'check_out' | null;
    lastPunchedAt: string | null;
    timezone: string;
    locationMode: 'disabled' | 'capture' | 'required' | 'geofence_required';
    locationAvailable: boolean;
    lastLocationResult: 'captured' | 'provided' | 'verified' | null;
}

type PunchAction = 'in' | 'out';
type LocationPayload = { latitude?: number; longitude?: number; location_accuracy_meters?: number };

interface EmployeePunchCardProps {
    initialState: EmployeePunchState;
}

function isEmployeePunchState(value: unknown): value is EmployeePunchState {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const state = value as Record<string, unknown>;

    return (
        (state.state === 'not_started' || state.state === 'clocked_in' || state.state === 'clocked_out') &&
        typeof state.canPunchIn === 'boolean' &&
        typeof state.canPunchOut === 'boolean' &&
        (state.reasonCode === null || state.reasonCode === 'ALREADY_PUNCHED_IN' || state.reasonCode === 'ALREADY_PUNCHED_OUT' || state.reasonCode === 'LOCATION_NOT_CONFIGURED') &&
        (state.lastPunchType === null || state.lastPunchType === 'check_in' || state.lastPunchType === 'check_out') &&
        (state.lastPunchedAt === null || typeof state.lastPunchedAt === 'string') &&
        typeof state.timezone === 'string' &&
        (state.locationMode === 'disabled' || state.locationMode === 'capture' || state.locationMode === 'required' || state.locationMode === 'geofence_required') &&
        typeof state.locationAvailable === 'boolean' &&
        (state.lastLocationResult === null || state.lastLocationResult === 'captured' || state.lastLocationResult === 'provided' || state.lastLocationResult === 'verified')
    );
}

function formatCompanyLocalTimestamp(value: string): string {
    const parts = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);

    if (!parts) {
        return value;
    }

    const [, year, month, day, hourValue, minute] = parts;
    const monthName = new Intl.DateTimeFormat(undefined, { month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(year), Number(month) - 1, 1)));
    const hourNumber = Number(hourValue);
    const hour = hourNumber % 12 || 12;
    const period = hourNumber >= 12 ? 'PM' : 'AM';

    return `${monthName} ${Number(day)}, ${year} at ${hour}:${minute} ${period}`;
}

function stateLabel(state: EmployeePunchState): string {
    if (state.state === 'clocked_in') {
        return 'Currently punched in';
    }

    if (state.state === 'clocked_out') {
        return 'Last punch was a punch out';
    }

    return 'No punch has been recorded yet';
}

function reasonMessage(reasonCode: EmployeePunchState['reasonCode']): string | null {
    if (reasonCode === 'ALREADY_PUNCHED_IN') {
        return 'You are already punched in. Your current status is shown below.';
    }

    if (reasonCode === 'ALREADY_PUNCHED_OUT') {
        return 'Your last punch was a punch out. Your current status is shown below.';
    }

    if (reasonCode === 'LOCATION_NOT_CONFIGURED') {
        return 'Attendance location is not configured. Contact your HR team.';
    }

    return null;
}

function locationErrorMessage(error: GeolocationPositionError, required: boolean): string {
    if (error.code === error.PERMISSION_DENIED) {
        return required ? 'Location access is required to punch for this company. Allow location access and try again.' : 'Location was not recorded. You can still punch in.';
    }

    if (error.code === error.POSITION_UNAVAILABLE) {
        return required ? 'Your location is unavailable. Turn on location services and try again.' : 'Your location was unavailable, so it was not recorded.';
    }

    if (error.code === error.TIMEOUT) {
        return required ? 'The location check took too long. Try again where you have a stronger signal.' : 'The location check timed out, so location was not recorded.';
    }

    return required ? 'We could not get your location. Check location services and try again.' : 'Location was not available, so it was not recorded.';
}

export default function EmployeePunchCard({ initialState }: EmployeePunchCardProps) {
    const [punchState, setPunchState] = useState(initialState);
    const [refreshing, setRefreshing] = useState(false);
    const [checkingLocation, setCheckingLocation] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [stateConfirmed, setStateConfirmed] = useState(true);
    const [punchUnavailable, setPunchUnavailable] = useState(false);
    const [feedback, setFeedback] = useState<{ kind: 'success' | 'error' | 'info'; message: string } | null>(null);
    const requestActive = useRef(false);
    const permissionDenied = useRef(false);
    const domainError = useRef<string | null>(null);

    useEffect(() => {
        setPunchState(initialState);
        setStateConfirmed(true);
        setPunchUnavailable(false);
        setFeedback(null);
        permissionDenied.current = false;
        domainError.current = null;
    }, [initialState]);

    useEffect(() => {
        const handleInvalidResponse = router.on('invalid', (event) => {
            if (!requestActive.current) {
                return;
            }

            event.preventDefault();

            if (event.detail.response.status === 403) {
                permissionDenied.current = true;
                setPunchUnavailable(true);
                setFeedback({ kind: 'error', message: 'Punching is not available for your account right now. Contact HR if you need help.' });
            } else {
                setFeedback({ kind: 'info', message: 'Checking your latest attendance status…' });
            }
        });
        const handleRequestException = router.on('exception', (event) => {
            if (!requestActive.current) {
                return;
            }

            event.preventDefault();
            setFeedback({ kind: 'info', message: 'We could not confirm the punch response. Checking your latest attendance status…' });
        });

        return () => {
            handleInvalidResponse();
            handleRequestException();
        };
    }, []);

    async function refreshPunchState(action?: PunchAction): Promise<boolean> {
        requestActive.current = true;
        setRefreshing(true);

        try {
            const response = await fetch(route('employee.attendance.punch-state'), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Punch state refresh failed.');
            }

            const responseData: unknown = await response.json();
            if (!isEmployeePunchState(responseData)) {
                throw new Error('Punch state response was invalid.');
            }

            setPunchState(responseData);
            setStateConfirmed(true);

            if (domainError.current) {
                setFeedback({ kind: 'error', message: domainError.current });
                domainError.current = null;
            } else if (action === 'in' && responseData.state === 'clocked_in') {
                setFeedback({ kind: 'success', message: responseData.lastLocationResult === 'verified' ? 'Punched in successfully. Location verified.' : 'Punched in successfully.' });
            } else if (action === 'out' && responseData.state === 'clocked_out') {
                setFeedback({ kind: 'success', message: responseData.lastLocationResult === 'verified' ? 'Punched out successfully. Location verified.' : 'Punched out successfully.' });
            } else if (action) {
                setFeedback({ kind: 'info', message: 'Your latest attendance status has been refreshed. Follow the action shown below.' });
            } else {
                setFeedback({ kind: 'info', message: 'Your attendance status is up to date.' });
            }

            return true;
        } catch {
            setStateConfirmed(false);
            setFeedback({ kind: 'error', message: "We couldn't confirm your latest attendance status. Refresh before trying again." });

            return false;
        } finally {
            requestActive.current = false;
            setRefreshing(false);
        }
    }

    function sendPunch(action: PunchAction, location: LocationPayload = {}): void {
        setCheckingLocation(false);
        setSubmitting(true);
        setFeedback({ kind: 'info', message: action === 'in' ? 'Punching in…' : 'Punching out…' });
        router.post(route(action === 'in' ? 'employee.attendance.punch-in' : 'employee.attendance.punch-out'), location, {
            preserveScroll: true,
            onError: (errors) => {
                if (typeof errors.location === 'string') {
                    domainError.current = errors.location;
                }
                setFeedback({ kind: 'info', message: 'Checking your latest attendance status…' });
            },
            onFinish: () => {
                setSubmitting(false);
                if (!permissionDenied.current) {
                    void refreshPunchState(action);
                } else {
                    requestActive.current = false;
                }
            },
        });
    }

    function submitPunch(action: PunchAction): void {
        if (requestActive.current || submitting || refreshing || checkingLocation || !stateConfirmed) {
            return;
        }

        requestActive.current = true;
        permissionDenied.current = false;
        domainError.current = null;

        if (punchState.locationMode === 'disabled') {
            sendPunch(action);
            return;
        }

        setCheckingLocation(true);
        setFeedback({ kind: 'info', message: 'Checking location…' });
        const required = punchState.locationMode === 'required' || punchState.locationMode === 'geofence_required';
        const geolocation = typeof navigator !== 'undefined' ? navigator.geolocation : undefined;

        if (!geolocation) {
            if (required) {
                requestActive.current = false;
                setCheckingLocation(false);
                setFeedback({ kind: 'error', message: 'Location is unavailable in this browser. Allow location access or contact HR.' });
                return;
            }
            sendPunch(action);
            return;
        }

        geolocation.getCurrentPosition(
            (position) => {
                sendPunch(action, {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    location_accuracy_meters: position.coords.accuracy,
                });
            },
            (error) => {
                if (required) {
                    requestActive.current = false;
                    setCheckingLocation(false);
                    setFeedback({ kind: 'error', message: locationErrorMessage(error, true) });
                    return;
                }
                setFeedback({ kind: 'info', message: locationErrorMessage(error, false) });
                sendPunch(action);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    }

    const action = punchUnavailable ? null : punchState.canPunchIn ? 'in' : punchState.canPunchOut ? 'out' : null;
    const busy = submitting || refreshing || checkingLocation;
    const currentReason = reasonMessage(punchState.reasonCode);
    const locationNotice = punchState.locationMode === 'capture'
        ? 'Your company may record your location with this punch if you allow it.'
        : punchState.locationMode === 'required'
            ? 'Your company requires location access when you punch in or out.'
            : punchState.locationMode === 'geofence_required'
                ? 'Your company requires your location to be within an approved worksite when you punch.'
                : null;

    return (
        <Card className="min-w-0 border-primary/20 bg-card shadow-sm">
            <CardHeader className="gap-2 p-4 sm:p-6">
                <CardDescription>Attendance punch</CardDescription>
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <span aria-hidden="true" className={`h-2.5 w-2.5 shrink-0 rounded-full ${punchState.state === 'clocked_in' ? 'bg-emerald-600' : 'bg-muted-foreground'}`} />
                    <h2 className="break-words text-xl font-semibold leading-tight tracking-tight">{stateLabel(punchState)}</h2>
                </div>
            </CardHeader>
            <CardContent className="flex min-w-0 flex-col gap-4 p-4 pt-0 sm:p-6 sm:pt-0">
                {currentReason && <p className="text-sm text-muted-foreground">{currentReason}</p>}
                {locationNotice && <p className="break-words rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">{locationNotice}</p>}

                {punchState.lastPunchType && punchState.lastPunchedAt ? (
                    <div className="min-w-0 rounded-md bg-muted/50 p-3">
                        <p className="text-sm text-muted-foreground">Last punch · {punchState.lastPunchType === 'check_in' ? 'Punch In' : 'Punch Out'}</p>
                        <p className="break-words font-medium">{formatCompanyLocalTimestamp(punchState.lastPunchedAt)}</p>
                        {punchState.lastLocationResult === 'verified' && <p className="mt-1 text-xs text-muted-foreground">Location verified</p>}
                        {punchState.lastLocationResult === 'captured' && <p className="mt-1 text-xs text-muted-foreground">Location recorded</p>}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">You haven&apos;t recorded a punch yet.</p>
                )}

                {action ? (
                    <Button
                        type="button"
                        size="lg"
                        className="min-h-12 w-full text-base sm:w-auto sm:min-w-44"
                        disabled={busy || !stateConfirmed || !punchState.locationAvailable}
                        onClick={() => submitPunch(action)}
                    >
                        {busy ? <LoaderCircle aria-hidden="true" className="animate-spin" /> : null}
                        {checkingLocation ? 'Checking location…' : busy ? (submitting ? (action === 'in' ? 'Punching in…' : 'Punching out…') : 'Checking status…') : action === 'in' ? 'Punch In' : 'Punch Out'}
                    </Button>
                ) : (
                    <p className="text-sm text-muted-foreground">Punching isn&apos;t available right now. Contact your HR team if you need help.</p>
                )}

                {!stateConfirmed && (
                    <Button type="button" variant="outline" className="min-h-11 w-full sm:w-auto" disabled={busy} onClick={() => void refreshPunchState()}>
                        <RefreshCw aria-hidden="true" />
                        Refresh attendance status
                    </Button>
                )}

                {feedback && (
                    <Alert variant={feedback.kind === 'error' ? 'destructive' : 'default'} role={feedback.kind === 'error' ? 'alert' : 'status'} aria-live={feedback.kind === 'error' ? 'assertive' : 'polite'} className="break-words">
                        <AlertDescription>{feedback.message}</AlertDescription>
                    </Alert>
                )}

                <p className="break-words text-xs text-muted-foreground">Punches are recorded using server time. Times shown in {punchState.timezone}.</p>
            </CardContent>
        </Card>
    );
}
