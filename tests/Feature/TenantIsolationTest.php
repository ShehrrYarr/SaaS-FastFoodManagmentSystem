<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\MissingTenantException;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    // ---------------------------------------------------------------- routing & middleware

    public function test_shop_user_can_open_their_own_workspace(): void
    {
        $shop = $this->makeShop('aaa');

        $this->actingAs($this->ownerOf($shop), 'shop')
            ->get('/aaa/dashboard')
            ->assertOk()
            ->assertSee('AAA Shop');
    }

    public function test_user_of_shop_a_cannot_open_shop_b_by_changing_the_prefix(): void
    {
        $a = $this->makeShop('aaa');
        $this->makeShop('bbb');

        $this->actingAs($this->ownerOf($a), 'shop')
            ->get('/bbb/dashboard')
            ->assertForbidden();
    }

    public function test_guests_are_sent_to_that_shops_login_page(): void
    {
        $this->makeShop('aaa');

        $this->get('/aaa/dashboard')->assertRedirect('/aaa/login');
        $this->get('/aaa/login')->assertOk()->assertSee('AAA Shop');
    }

    public function test_the_shop_root_opens_its_dashboard(): void
    {
        $shop = $this->makeShop('aaa');

        $this->actingAs($this->ownerOf($shop), 'shop')->get('/aaa')->assertRedirect('/aaa/dashboard');
    }

    public function test_unknown_and_reserved_prefixes_are_not_found(): void
    {
        $this->get('/nope/login')->assertNotFound();
        $this->get('/pos/login')->assertNotFound();
    }

    public function test_prefix_is_case_insensitive(): void
    {
        $shop = $this->makeShop('aaa');

        $this->actingAs($this->ownerOf($shop), 'shop')->get('/AAA/dashboard?x=1')->assertRedirect('/aaa/dashboard?x=1');
    }

    public function test_suspending_a_shop_signs_its_staff_out(): void
    {
        $shop = $this->makeShop('aaa');
        $owner = $this->ownerOf($shop);
        $shop->update(['is_active' => false, 'suspended_reason' => 'Unpaid']);

        $this->actingAs($owner, 'shop')
            ->get('/aaa/dashboard')
            ->assertRedirect('/aaa/login')
            ->assertSessionHas('login_error');

        $this->assertGuest('shop');
    }

    public function test_an_expired_subscription_blocks_access(): void
    {
        $shop = $this->makeShop('aaa', [
            'subscription_ends_at' => Carbon::now(Shop::DEFAULT_TIMEZONE)->subDay()->toDateString(),
        ]);

        $this->actingAs($this->ownerOf($shop), 'shop')->get('/aaa/dashboard')->assertRedirect('/aaa/login');
    }

    public function test_a_subscription_is_valid_through_its_last_day(): void
    {
        $shop = $this->makeShop('aaa', [
            'subscription_ends_at' => Carbon::now(Shop::DEFAULT_TIMEZONE)->toDateString(),
        ]);

        $this->actingAs($this->ownerOf($shop), 'shop')->get('/aaa/dashboard')->assertOk();
        $this->assertSame('expiring', $shop->fresh()->status());
    }

    public function test_a_disabled_user_is_signed_out(): void
    {
        $shop = $this->makeShop('aaa');
        $owner = $this->ownerOf($shop);
        $owner->update(['is_active' => false]);

        $this->actingAs($owner, 'shop')->get('/aaa/dashboard')->assertRedirect('/aaa/login');
        $this->assertGuest('shop');
    }

    public function test_shop_users_cannot_open_the_super_admin_area(): void
    {
        $shop = $this->makeShop('aaa');

        $this->actingAs($this->ownerOf($shop), 'shop')
            ->get('/super-admin')
            ->assertRedirect('/super-admin/login');
    }

    public function test_a_super_admin_session_does_not_grant_shop_access(): void
    {
        $this->makeShop('aaa');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'admin')->get('/aaa/dashboard')->assertRedirect('/aaa/login');
    }

    public function test_super_admin_pages_load(): void
    {
        $shop = $this->makeShop('aaa');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'admin');

        $this->get('/super-admin')->assertOk();
        $this->get('/super-admin/shops')->assertOk()->assertSee('AAA Shop');
        $this->get('/super-admin/shops/create')->assertOk();
        $this->get('/super-admin/shops/'.$shop->id)->assertOk()->assertSee('AAA Shop');
        $this->get('/super-admin/shops/'.$shop->id.'?tab=users')->assertOk()->assertSee('owner@aaa.test');
        $this->get('/super-admin/admins')->assertOk();
    }

    // ---------------------------------------------------------------- global scope

    public function test_tenant_scope_only_returns_the_current_shops_rows(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $tenancy = app(TenantContext::class);

        $tenantIds = $tenancy->run($a, fn () => Role::query()->pluck('tenant_id')->unique()->values()->all());

        $this->assertSame([$a->id], $tenantIds);
        $this->assertSame(3, $tenancy->run($b, fn () => Role::query()->count()));
    }

    public function test_tenant_scope_returns_nothing_without_a_shop(): void
    {
        $this->makeShop('aaa');
        app(TenantContext::class)->forget();

        $this->assertSame(0, Role::query()->count());
        $this->assertSame(3, Role::query()->acrossShops()->count());
    }

    public function test_find_cannot_load_another_shops_row(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $roleOfB = Role::query()->acrossShops()->where('tenant_id', $b->id)->firstOrFail();

        $found = app(TenantContext::class)->run($a, fn () => Role::query()->find($roleOfB->id));

        $this->assertNull($found);
    }

    public function test_creating_a_shop_record_without_a_current_shop_fails(): void
    {
        app(TenantContext::class)->forget();

        $this->expectException(MissingTenantException::class);

        Role::create(['name' => 'Stray', 'permissions' => []]);
    }

    public function test_a_record_cannot_be_moved_to_another_shop(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');

        $this->expectException(MissingTenantException::class);

        app(TenantContext::class)->run($a, function () use ($b) {
            $role = Role::query()->firstOrFail();
            $role->tenant_id = $b->id;
            $role->save();
        });
    }
}
