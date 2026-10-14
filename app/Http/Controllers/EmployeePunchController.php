<?php

namespace App\Http\Controllers;

use App\Actions\AttendancePunches\CreateWebAttendancePunch;
use App\Enums\AttendancePunchType;
use App\Http\Requests\EmployeePunch\StoreEmployeePortalPunchRequest;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\EmployeePunchStateQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class EmployeePunchController extends Controller
{
    public function state(Request $request, ActiveCompany $activeCompany, EmployeePunchStateQuery $stateQuery): JsonResponse
    {
        [$employee, $company] = $this->portalIdentity($request, $activeCompany);

        return response()->json($stateQuery->forEmployee($employee, $company)->toArray());
    }

    public function punchIn(
        StoreEmployeePortalPunchRequest $request,
        ActiveCompany $activeCompany,
        CreateWebAttendancePunch $punch,
    ): RedirectResponse {
        return $this->recordPunch($request, $activeCompany, $punch, AttendancePunchType::CheckIn);
    }

    public function punchOut(
        StoreEmployeePortalPunchRequest $request,
        ActiveCompany $activeCompany,
        CreateWebAttendancePunch $punch,
    ): RedirectResponse {
        return $this->recordPunch($request, $activeCompany, $punch, AttendancePunchType::CheckOut);
    }

    private function recordPunch(
        StoreEmployeePortalPunchRequest $request,
        ActiveCompany $activeCompany,
        CreateWebAttendancePunch $punch,
        AttendancePunchType $type,
    ): RedirectResponse {
        [$employee, $company] = $this->portalIdentity($request, $activeCompany);
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);
        Gate::authorize('selfPunch', [AttendancePunch::class, $company, $employee]);

        $punch->handle(
            $company,
            $employee,
            $actor,
            $type,
            $request->safe()->only(['latitude', 'longitude', 'location_accuracy_meters']),
            $request->ip(),
            $request->userAgent(),
        );

        $message = $type === AttendancePunchType::CheckIn
            ? 'Check-in recorded.'
            : 'Check-out recorded.';

        return back()->with('success', $message);
    }

    /** @return array{Employee, Company} */
    private function portalIdentity(Request $request, ActiveCompany $activeCompany): array
    {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);

        return [$employee, $company];
    }
}
