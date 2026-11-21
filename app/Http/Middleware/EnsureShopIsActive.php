<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs staff out as soon as their shop is suspended or its subscription
 * ends, and sends them to the login page with the reason.
 */
class EnsureShopIsActive
{
    public function __construct(private TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = $this->tenancy->shop();

        if ($shop && ! $shop->isAccessible()) {
            Auth::guard('shop')->logout();
            session()->flash('login_error', $shop->blockedReason());

            throw new AuthenticationException('Shop unavailable.', ['shop'], route('shop.login'));
        }

        return $next($request);
    }
}
