<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveTeamCalendarRequest;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Services\Leave\LeaveTeamCalendarQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class LeaveTeamCalendarController extends Controller
{
    public function __invoke(
        LeaveTeamCalendarRequest $request,
        ActiveCompany $activeCompany,
        LeaveRequestReviewerScope $reviewerScope,
        LeaveTeamCalendarQuery $calendar,
    ): Response {
        $company = $activeCompany->current();
        abort_unless($company instanceof Company, 403);
        Gate::authorize('viewAny', [LeaveRequest::class, $company]);

        $filters = $request->validated();
        $data = $calendar->forReviewer($request->user(), $company, $filters, $reviewerScope);

        return Inertia::render('leave-calendar/index', $data->toArray());
    }
}
