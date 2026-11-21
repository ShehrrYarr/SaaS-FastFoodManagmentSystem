<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopSequence;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopOnboardingTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_super_admin_creates_a_shop_with_its_owner_login_in_one_flow(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(), 'admin');

        Livewire::test('pages::admin.shops.create')
            ->set('name', 'Burger Lab Gulberg')
            ->assertSet('prefix', 'blg')
            ->assertSet('orderPrefix', 'BLG')
            ->call('next')->assertHasNoErrors()->assertSet('step', 2)
            ->call('next')->assertSet('step', 3)
            ->set('ownerName', 'Hamza Riaz')
            ->set('ownerEmail', 'Owner@BLG.test')
            ->set('ownerPassword', 'secret-123')
            ->call('next')->assertSet('step', 4)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $shop = Shop::query()->where('prefix', 'blg')->firstOrFail();
        $owner = $this->ownerOf($shop);

        $this->assertFalse($shop->inventory_tracking_enabled, 'Inventory tracking must be off by default.');
        $this->assertSame('BLG', $shop->order_prefix);
        $this->assertSame(['Owner', 'Manager', 'Cashier'], $shop->roles()->orderBy('id')->pluck('name')->all());
        $this->assertTrue($owner->isOwner());
        $this->assertSame('owner@blg.test', $owner->email);
    }

    public function test_reserved_duplicate_and_malformed_prefixes_are_rejected(): void
    {
        $this->makeShop('blg');
        $this->actingAs(User::factory()->superAdmin()->create(), 'admin');

        foreach (['super-admin', 'pos', 'livewire-1234', 'blg', '-ab', 'a'] as $prefix) {
            Livewire::test('pages::admin.shops.create')
                ->set('name', 'Some Shop')
                ->set('prefix', $prefix)
                ->call('next')
                ->assertHasErrors('prefix')
                ->assertSet('step', 1);
        }
    }

    public function test_suggested_prefix_skips_taken_ones(): void
    {
        $this->makeShop('blg');
        $this->actingAs(User::factory()->superAdmin()->create(), 'admin');

        Livewire::test('pages::admin.shops.create')
            ->set('name', 'Burger Lab Gulberg')
            ->assertSet('prefix', 'blg2');
    }

    public function test_shop_login_only_accepts_that_shops_staff(): void
    {
        $a = $this->makeShop('aaa');
        $this->makeShop('bbb');
        $this->enterShop($a);

        Livewire::test('pages::shop.login')
            ->set('login', 'owner@bbb.test')
            ->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('login');

        $this->assertGuest('shop');

        Livewire::test('pages::shop.login')
            ->set('login', 'OWNER@aaa.test')
            ->set('password', 'password')
            ->call('signIn')
            ->assertHasNoErrors()
            ->assertRedirect('/aaa/dashboard');

        $this->assertAuthenticatedAs($this->ownerOf($a), 'shop');
    }

    public function test_staff_of_a_suspended_shop_cannot_sign_in(): void
    {
        $shop = $this->makeShop('aaa', ['is_active' => false, 'suspended_reason' => 'Unpaid']);
        $this->enterShop($shop);

        Livewire::test('pages::shop.login')
            ->set('login', 'owner@aaa.test')
            ->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('login');

        $this->assertGuest('shop');
    }

    public function test_super_admin_login_rejects_shop_accounts(): void
    {
        $this->makeShop('aaa');

        Livewire::test('pages::admin.login')
            ->set('email', 'owner@aaa.test')
            ->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('email');

        $this->assertGuest('admin');

        $admin = User::factory()->superAdmin()->create(['email' => 'root@sfms.test']);

        Livewire::test('pages::admin.login')
            ->set('email', 'root@sfms.test')
            ->set('password', 'password')
            ->call('signIn')
            ->assertHasNoErrors()
            ->assertRedirect('/super-admin');

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_signing_out_of_a_shop_keeps_the_super_admin_session(): void
    {
        $shop = $this->makeShop('aaa');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'admin')
            ->actingAs($this->ownerOf($shop), 'shop')
            ->post('/aaa/logout')
            ->assertRedirect('/aaa/login');

        $this->assertGuest('shop');
        $this->assertTrue(Auth::guard('admin')->check());
    }

    public function test_roles_drive_permissions(): void
    {
        $shop = $this->makeShop('aaa');

        $cashier = app(TenantContext::class)->run($shop, fn () => User::factory()->forShop($shop, 'Cashier')->create());
        $owner = $this->ownerOf($shop);

        $this->assertTrue($cashier->hasPermission('pos.use'));
        $this->assertFalse($cashier->hasPermission('settings.manage'));
        $this->assertTrue($owner->hasPermission('settings.manage'));
        $this->assertTrue($owner->can('roles.manage'));
        $this->assertFalse($cashier->can('staff.manage'));
    }

    public function test_sequences_are_gap_free_per_shop_and_scope(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');

        $this->assertSame(1, ShopSequence::next($a->id, 'order'));
        $this->assertSame(2, ShopSequence::next($a->id, 'order'));
        $this->assertSame(1, ShopSequence::next($b->id, 'order'));
        $this->assertSame(1, ShopSequence::next($a->id, 'token', '2026-11-21'));
        $this->assertSame(2, ShopSequence::next($a->id, 'token', '2026-11-21'));
        $this->assertSame(1, ShopSequence::next($a->id, 'token', '2026-11-22'));

        $this->assertSame('BLG-000123', ShopSequence::format('BLG', 123));
        $this->assertSame('BLG-PUR-000045', ShopSequence::format('BLG', 45, 'PUR'));
    }

    /** What ResolveShopByPrefix does for a real request. */
    private function enterShop(Shop $shop): void
    {
        app(TenantContext::class)->set($shop);
        URL::defaults(['shop' => $shop->prefix]);
    }
}
