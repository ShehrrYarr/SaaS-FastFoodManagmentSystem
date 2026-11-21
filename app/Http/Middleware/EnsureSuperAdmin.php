<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('admin')->user();

        if (! $user || ! $user->is_super_admin || $user->tenant_id !== null || ! $user->is_active) {
            Auth::guard('admin')->logout();

            throw new AuthenticationException('Unauthenticated.', ['admin'], route('admin.login'));
        }

        return $next($request);
    }
}
