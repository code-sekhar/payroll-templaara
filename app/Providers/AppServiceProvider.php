<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->scoped(ActiveCompany::class);

        Gate::before(function (User $user): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
