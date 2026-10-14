<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\Employees\EmployeePortalAccess;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly ActiveCompany $activeCompany,
        private readonly EmployeePortalAccess $employeePortalAccess,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
        $isEmployeePortalRoute = $request->routeIs('employee.portal', 'employee.profile.show', 'employee.leave.*', 'employee.attendance.index', 'employee.attendance.corrections.*', 'employee.notifications.*');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'auth' => [
                'user' => function () use ($request, $isEmployeePortalRoute): ?array {
                    if ($request->routeIs('employee-invitations.*') || $isEmployeePortalRoute) {
                        return null;
                    }

                    $user = $request->user();

                    if (! $user) {
                        return null;
                    }

                    $isSuperAdmin = $user->isSuperAdmin();

                    return array_merge($user->only('id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'), [
                        'roles' => $isSuperAdmin ? ['super_admin'] : $user->getRoleNames(),
                        'permissions' => $isSuperAdmin
                            ? Permission::where('guard_name', 'web')->pluck('name')
                            : $user->getAllPermissions()->pluck('name'),
                    ]);
                },
            ],
            'activeCompany' => fn () => $request->routeIs('employee-invitations.*')
                || $isEmployeePortalRoute
                ? null
                : $this->activeCompany->current()?->only('id', 'name', 'slug'),
            'companyAccess' => fn () => ! $request->routeIs('employee-invitations.*') && ! $isEmployeePortalRoute && $request->user()
                ? ($request->user()->isSuperAdmin() || $request->user()->hasPermissionInAnyCompany('company.view'))
                : false,
            'companies' => fn () => $request->routeIs('employee-invitations.*')
                || $isEmployeePortalRoute
                ? []
                : ($request->user()
                ? ($request->user()->isSuperAdmin()
                    ? Company::query()->orderBy('name')->get(['id', 'name', 'slug'])
                    : $request->user()->companiesWithPermission('company.view')->values()->map->only(['id', 'name', 'slug']))
                : []),
            'employeePortalAccess' => function () use ($request): bool {
                if ($request->routeIs('employee.portal', 'employee.profile.show', 'employee.leave.*', 'employee.attendance.index', 'employee.attendance.corrections.*', 'employee.notifications.*')) {
                    return false;
                }

                $user = $request->user();
                $company = $this->activeCompany->current();

                return $user instanceof User && $company instanceof Company
                    && $this->employeePortalAccess->employeeFor($user, $company) !== null;
            },
        ]);
    }
}
