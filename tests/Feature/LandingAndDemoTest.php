<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** The public landing page and the one-tap demo (config sfms.demo_shop). */
class LandingAndDemoTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_the_landing_page_explains_the_product_and_links_to_whatsapp(): void
    {
        config(['sfms.demo_shop' => 'blg', 'sfms.whatsapp' => '923120883979']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Know every rupee.')
            ->assertSee('Ask for the pricing')
            ->assertSee('https://wa.me/923120883979?text=', false)
            ->assertSee('+92 312 0883979')
            ->assertSee(route('demo', ['role' => 'owner']), false)
            ->assertSee(route('demo', ['role' => 'manager']), false)
            ->assertSee(route('demo', ['role' => 'cashier']), false);
    }

    public function test_without_a_demo_shop_the_page_offers_a_demo_on_whatsapp_instead(): void
    {
        config(['sfms.demo_shop' => null]);

        $this->get('/')->assertOk()->assertDontSee('/demo/owner')->assertSee('Ask for a demo');
        $this->get('/demo/owner')->assertNotFound();
    }

    public function test_the_shop_code_form_and_the_finder_page(): void
    {
        $shop = $this->makeShop('aaa');

        Livewire::test('pages::landing')->set('code', 'nope')->call('go')->assertHasErrors('code');
        Livewire::test('pages::landing')->set('code', ' AAA/ ')->call('go')->assertRedirect($shop->loginUrl());

        $this->get('/find')->assertOk()->assertSee('Enter your shop code');
    }

    public function test_each_role_signs_straight_into_the_demo_shop(): void
    {
        $shop = $this->demoShop();

        foreach (['owner' => 'dashboard', 'manager' => 'dashboard', 'cashier' => 'pos'] as $role => $landing) {
            $this->get("/demo/{$role}")->assertRedirect(url("blg/{$landing}"));
            $this->assertSame(ucfirst($role), auth('shop')->user()->role->name);
            $this->assertSame($shop->id, auth('shop')->user()->tenant_id);
            $this->get("/blg/{$landing}")->assertOk()->assertSee('Try anything; the demo resets every night.');
            auth('shop')->logout();
        }
    }

    public function test_a_visitor_cannot_lock_the_next_one_out(): void
    {
        $shop = $this->demoShop();

        // A previous visitor disabled the cashier and stripped the role's permissions.
        app(TenantContext::class)->run($shop, function () {
            $role = Role::query()->where('name', 'Cashier')->sole();
            $role->forceFill(['permissions' => []])->save();
            User::query()->where('role_id', $role->id)->update(['is_active' => false]);
        });

        $this->get('/demo/cashier')->assertRedirect(url('blg/pos'));
        $this->get('/blg/pos')->assertOk();

        app(TenantContext::class)->run($shop, function () {
            $role = Role::query()->where('name', 'Cashier')->sole();
            $this->assertSame(Permissions::forDefaultRole('Cashier'), $role->permissions);
            $this->assertTrue(User::query()->where('role_id', $role->id)->sole()->is_active);
        });
    }

    public function test_the_landing_target_stays_inside_the_demo_shop(): void
    {
        $this->demoShop();

        $this->get('/demo/owner?to=reports')->assertRedirect(url('blg/reports'));

        foreach (['//evil.example', 'https://evil.example', '../super-admin', '/x'] as $bad) {
            $this->get('/demo/owner?to='.urlencode($bad))->assertRedirect(url('blg/dashboard'));
        }
    }

    public function test_a_suspended_demo_shop_or_unknown_role_is_not_found(): void
    {
        $shop = $this->demoShop();

        $this->get('/demo/admin')->assertNotFound();

        $shop->update(['is_active' => false]);
        $this->get('/demo/owner')->assertNotFound();
        $this->assertGuest('shop');
    }

    public function test_the_demo_reset_only_runs_on_a_demo_install(): void
    {
        config(['sfms.demo_shop' => null]);

        $this->artisan('sfms:demo-reset', ['--force' => true])
            ->expectsOutputToContain('only runs on a demo install')
            ->assertFailed();
    }

    private function demoShop(): Shop
    {
        config(['sfms.demo_shop' => 'blg']);
        $shop = $this->makeShop('blg');

        app(TenantContext::class)->run($shop, function () use ($shop) {
            foreach (['Manager', 'Cashier'] as $role) {
                User::factory()->forShop($shop, $role)->create();
            }
        });

        return $shop;
    }
}
