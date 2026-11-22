<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inventory screens only exist for shops that track stock (Settings →
 * "Track ingredient inventory"). Otherwise go back to the dashboard.
 */
class EnsureInventoryEnabled
{
    public function __construct(private TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenancy->shop()?->inventory_tracking_enabled) {
            return redirect()->route('shop.dashboard')->with('toast', [
                'type' => 'info',
                'message' => 'Inventory tracking is off for this shop. Turn it on in Settings to use these pages.',
            ]);
        }

        return $next($request);
    }
}
