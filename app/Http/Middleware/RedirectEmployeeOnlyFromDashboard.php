<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\Employees\EmployeePortalAccess;
use App\Tenancy\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectEmployeeOnlyFromDashboard
{
    public function __construct(
        private readonly ActiveCompany $activeCompany,
        private readonly EmployeePortalAccess $portalAccess,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $company = $this->activeCompany->current();

        if ($user instanceof User && $company instanceof Company && $this->portalAccess->hasOnlyEmployeeRole($user, $company)) {
            abort_unless($this->portalAccess->isEmployeeOnly($user, $company), 403);

            return redirect()->route('employee.portal');
        }

        return $next($request);
    }
}
