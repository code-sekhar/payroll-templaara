<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\Notifications\NotificationCenterQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationCenterController extends Controller
{
    public function index(Request $request, ActiveCompany $activeCompany, NotificationCenterQuery $notifications): Response
    {
        $company = $activeCompany->current();
        abort_unless($company instanceof Company && $request->user() instanceof User, 403);

        $filter = $request->validate(['filter' => ['sometimes', 'string', Rule::in(['all', 'unread'])]])['filter'] ?? 'all';

        return Inertia::render('notifications/index', [
            'notificationCenter' => $notifications->page($request->user(), $company, $filter)->toArray(),
            'companyName' => $company->name,
            'routes' => [
                'readAll' => route('notifications.read-all'),
                'all' => route('notifications.index', ['filter' => 'all']),
                'unread' => route('notifications.index', ['filter' => 'unread']),
            ],
        ]);
    }

    public function read(Request $request, ActiveCompany $activeCompany, NotificationCenterQuery $notifications, string $notification): RedirectResponse
    {
        $company = $activeCompany->current();
        abort_unless($company instanceof Company && $request->user() instanceof User, 403);
        $notifications->markAsRead($request->user(), $company, $notification);

        return back()->with('success', 'Notification marked as read.');
    }

    public function readAll(Request $request, ActiveCompany $activeCompany, NotificationCenterQuery $notifications): RedirectResponse
    {
        $company = $activeCompany->current();
        abort_unless($company instanceof Company && $request->user() instanceof User, 403);
        $marked = $notifications->markAllAsRead($request->user(), $company);

        return back()->with('success', $marked === 0 ? 'There are no unread notifications to update.' : 'Authorized notifications marked as read.');
    }
}
