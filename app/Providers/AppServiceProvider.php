<?php

namespace App\Providers;

use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserBelongsToShop;
use App\Http\Middleware\ResolveShopByPrefix;
use App\Models\AccountTransaction;
use App\Models\BusinessDay;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\LiveUpdates;
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

        // What changed during this request, sent to open screens when it ends.
        $this->app->scoped(LiveUpdates::class);
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

        $this->registerLiveUpdates();
    }

    /**
     * Every change to orders, the business day, money or stock is noted as it is saved,
     * and one "shop updated" message per shop goes out after the response (see LiveUpdates).
     */
    private function registerLiveUpdates(): void
    {
        $live = fn (): LiveUpdates => $this->app->make(LiveUpdates::class);

        Order::saved(fn (Order $order) => $live()->touch($order->tenant_id, 'orders', $order->id));
        OrderItem::saved(fn (OrderItem $item) => $live()->touch($item->tenant_id, 'orders', $item->order_id));
        BusinessDay::saved(fn (BusinessDay $day) => $live()->touch($day->tenant_id, 'day'));
        AccountTransaction::created(fn (AccountTransaction $entry) => $live()->touch($entry->tenant_id, 'money'));
        Expense::saved(fn (Expense $expense) => $live()->touch($expense->tenant_id, 'money'));
        StockMovement::created(fn (StockMovement $movement) => $live()->touch($movement->tenant_id, 'stock'));
        Ingredient::saved(fn (Ingredient $ingredient) => $live()->touch($ingredient->tenant_id, 'stock'));

        $this->app->terminating(fn () => $live()->flush());
    }
}
