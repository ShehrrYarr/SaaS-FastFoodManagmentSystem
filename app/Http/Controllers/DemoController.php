<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One-tap entry to the public demo shop (config sfms.demo_shop) as its Owner,
 * Manager or Cashier, no password. Visitors can change anything, so each
 * entry first repairs what would stop the demo working (the role's
 * permissions, the user being active); `sfms:demo-reset` rebuilds the rest
 * every night.
 */
class DemoController extends Controller
{
    public const ROLES = [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
    ];

    public function enter(Request $request, TenantContext $tenancy, string $role): RedirectResponse
    {
        $prefix = config('sfms.demo_shop');
        abort_unless(filled($prefix) && isset(self::ROLES[$role]), 404);

        $shop = Shop::query()->where('prefix', $prefix)->first();
        abort_unless($shop?->isAccessible(), 404);

        $user = $tenancy->run($shop, fn () => $this->demoUser($shop, self::ROLES[$role]));

        Auth::guard('shop')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->to(url($shop->prefix.'/'.$this->destination($request, $role)));
    }

    /** The demo user for a role, repaired so a previous visitor can't lock others out. */
    private function demoUser(Shop $shop, string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->first()
            ?? Role::create(['name' => $roleName, 'permissions' => Permissions::forDefaultRole($roleName), 'is_locked' => $roleName === 'Owner']);

        if ($role->permissions !== Permissions::forDefaultRole($roleName)) {
            $role->forceFill(['permissions' => Permissions::forDefaultRole($roleName)])->save();
        }

        $user = User::ofShop($shop)->where('role_id', $role->id)->oldest('id')->first()
            ?? User::create([
                'tenant_id' => $shop->id,
                'role_id' => $role->id,
                'name' => 'Demo '.$roleName,
                'username' => 'demo-'.Str::lower($roleName),
                'password' => Str::random(40),
            ]);

        if (! $user->is_active) {
            $user->forceFill(['is_active' => true])->save();
        }

        return $user;
    }

    /** Where to land: ?to=pos (a page inside the shop), else each role's natural start. */
    private function destination(Request $request, string $role): string
    {
        $to = (string) $request->query('to', '');

        if ($to !== '' && preg_match('#^[a-z0-9][a-z0-9\-/]{0,60}$#', $to) && ! str_contains($to, '//')) {
            return $to;
        }

        return $role === 'cashier' ? 'pos' : 'dashboard';
    }
}
