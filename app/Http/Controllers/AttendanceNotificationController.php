<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AttendanceCorrectionSubmitted;
use App\Services\Attendance\AttendanceNotificationInboxQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class AttendanceNotificationController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company, AttendanceNotificationInboxQuery $inbox): InertiaResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        abort_unless($request->user()->can('attendance_regularization.view'), 403);

        return Inertia::render('attendance-notifications/index', [
            'notifications' => $inbox->forManagement($request->user(), $activeCompany),
            'company' => $activeCompany->only('name'),
        ]);
    }

    public function open(Request $request, Company $company, string $notification): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        abort_unless($request->user()->can('attendance_regularization.view'), 403);

        /** @var User $user */
        $user = $request->user();
        $databaseNotification = $user->notifications()->whereKey($notification)->firstOrFail();
        abort_unless(
            $databaseNotification->type === AttendanceCorrectionSubmitted::class
                && (int) $databaseNotification->company_id === (int) $activeCompany->getKey(),
            404,
        );

        $regularization = AttendanceRegularizationRequest::query()
            ->where('company_id', $activeCompany->getKey())
            ->whereKey((int) ($databaseNotification->data['regularization_id'] ?? 0))
            ->firstOrFail();
        Gate::authorize('view', $regularization);

        $databaseNotification->markAsRead();

        return to_route('companies.attendance-regularizations.show', [$activeCompany, $regularization]);
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
