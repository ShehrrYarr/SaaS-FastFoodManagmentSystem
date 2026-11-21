<?php

namespace App\Providers;

use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserBelongsToShop;
use App\Http\Middleware\ResolveShopByPrefix;
use App\Models\User;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One current shop per request (reset between requests and queued jobs).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Re-run tenancy and access checks on every Livewire update request,
        // not only on the first page load.
        Livewire::addPersistentMiddleware([
            ResolveShopByPrefix::class,
            EnsureUserBelongsToShop::class,
            EnsureShopIsActive::class,
            EnsureSuperAdmin::class,
        ]);

        // Long-lived processes (Octane workers, the test runner) reuse memory between
        // requests. Livewire never resets its persistent-middleware state on its own, and
        // it skips the checks when the "previous" request had the same route, so a
        // different user's next request could bypass them. Reset it after every request.
        // (TenantContext is a scoped binding, which Octane and queue workers reset already.)
        $this->app->terminating(fn () => Livewire::flushState());

        // Shop permissions: @can('pos.use'), $this->authorize('menu.manage'), etc.
        Gate::before(function (User $user, string $ability) {
            return Permissions::exists($ability) ? $user->hasPermission($ability) : null;
        });

        Paginator::useBootstrapFive();
    }
}
