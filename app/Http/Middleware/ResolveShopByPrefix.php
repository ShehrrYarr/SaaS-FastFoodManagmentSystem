<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /{shop}/... to a shop and makes it the current tenant.
 *
 * Runs on every shop request and again on every Livewire update for
 * shop components (registered as Livewire persistent middleware).
 * Suspension is checked later by EnsureShopIsActive, so the login page
 * can still explain why a shop is unavailable.
 */
class ResolveShopByPrefix
{
    public function __construct(private TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $rawPrefix = (string) $route?->parameter('shop');
        $prefix = Str::lower($rawPrefix);

        $shop = $prefix !== '' && ! Shop::isReservedPrefix($prefix)
            ? Shop::query()->where('prefix', $prefix)->first()
            : null;

        abort_if($shop === null, 404);

        // /BLG/pos -> /blg/pos, so every link and bookmark uses one address.
        if ($rawPrefix !== $prefix && $request->isMethod('GET')) {
            $path = '/'.$prefix.substr('/'.ltrim($request->path(), '/'), strlen($rawPrefix) + 1);
            $query = $request->getQueryString();

            return redirect($path.($query ? '?'.$query : ''), 301);
        }

        $this->tenancy->set($shop);

        // route('shop.pos') etc. no longer need the prefix passed in.
        URL::defaults(['shop' => $shop->prefix]);

        // Components and controllers never receive the prefix as an argument.
        $route->forgetParameter('shop');

        View::share('currentShop', $shop);

        return $next($request);
    }
}
