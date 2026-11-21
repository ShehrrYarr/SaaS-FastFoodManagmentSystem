<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user signed in to Shop A can never open Shop B's workspace,
 * even by editing the prefix in the URL.
 */
class EnsureUserBelongsToShop
{
    public function __construct(private TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('shop')->user();
        $shopId = $this->tenancy->id();

        if (! $user || ! $shopId) {
            throw new AuthenticationException('Unauthenticated.', ['shop'], route('shop.login'));
        }

        if ((int) $user->tenant_id !== (int) $shopId || $user->is_super_admin) {
            abort(403, 'You are signed in to a different shop.');
        }

        if (! $user->is_active) {
            Auth::guard('shop')->logout();
            session()->flash('login_error', 'Your account has been disabled. Please ask your shop owner.');

            throw new AuthenticationException('Account disabled.', ['shop'], route('shop.login'));
        }

        return $next($request);
    }
}
