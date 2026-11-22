<?php

use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserBelongsToShop;
use App\Http\Middleware\ResolveShopByPrefix;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // POST /broadcasting/auth: shop staff only (see routes/channels.php).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth:shop']])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'shop.resolve' => ResolveShopByPrefix::class,
            'shop.member' => EnsureUserBelongsToShop::class,
            'shop.active' => EnsureShopIsActive::class,
            'super.admin' => EnsureSuperAdmin::class,
            'shop.inventory' => \App\Http\Middleware\EnsureInventoryEnabled::class,
        ]);

        // The shop must be known before auth runs, so guests are sent to the right login page.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveShopByPrefix::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            if (! $request->is('super-admin', 'super-admin/*') && app(TenantContext::class)->check()) {
                return route('shop.login');
            }

            return route('admin.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
