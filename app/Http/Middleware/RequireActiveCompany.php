<?php

namespace App\Http\Middleware;

use App\Tenancy\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveCompany
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->activeCompany->current() === null, 403, 'Select an active company first.');

        return $next($request);
    }
}
