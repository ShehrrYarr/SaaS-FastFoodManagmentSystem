<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\ComboItem;
use App\Models\Customer;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopSetupTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private const PAGES = [
        'settings', 'accounts', 'tables', 'waiters', 'staff', 'roles',
        'categories', 'products', 'products/create', 'add-ons', 'customers',
    ];

    // ---------------------------------------------------------------- access

    public function test_the_owner_can_open_every_setup_page(): void
    {
        $shop = $this->makeShop('aaa');
        $this->actingAs($this->ownerOf($shop), 'shop');

        foreach (self::PAGES as $page) {
            $this->get("/aaa/{$page}")->assertOk();
        }
    }

    public function test_a_cashier_only_reaches_the_pages_their_role_allows(): void
    {
        $shop = $this->makeShop('aaa');
        $cashier = $this->staff($shop, 'Cashier');
        $this->actingAs($cashier, 'shop');

        $this->get('/aaa/customers')->assertOk();

        foreach (['settings', 'accounts', 'tables', 'staff', 'roles', 'products', 'categories'] as $page) {
            $this->get("/aaa/{$page}")->assertForbidden();
        }
    }

    public function test_editing_another_shops_product_is_not_found(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $productOfB = $this->tenancy()->run($b, fn () => $this->product('Burger', ['Regular' => 500]));

        $this->actingAs($this->ownerOf($a), 'shop')
            ->get("/aaa/products/{$productOfB->id}/edit")
            ->assertNotFound();
    }

    public function test_livewire_actions_re_check_the_shop_on_every_request(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $ownerA = $this->ownerOf($a);

        $page = $this->actingAs($ownerA, 'shop')->get('/aaa/categories')->assertOk();

        // 1) Works while everything is fine.
        $this->livewireCall($page, 'add', updates: ['newName' => 'Burgers'])->assertOk();
        $this->assertSame(1, $this->tenancy()->run($a, fn () => Category::query()->count()));

        // 2) A user from Shop B replaying Shop A's component is refused.
        $this->actingAs($this->ownerOf($b), 'shop');
        $this->livewireCall($page, 'add', updates: ['newName' => 'Hijacked'])->assertForbidden();

        // 3) Once Shop A is suspended, its own owner is refused too.
        $a->update(['is_active' => false]);
        $this->actingAs($ownerA, 'shop');
        $this->assertNotSame(200, $this->livewireCall($page, 'add', updates: ['newName' => 'Drinks'])->status());

        $this->assertSame(['Burgers'], $this->tenancy()->run($a, fn () => Category::query()->pluck('name')->all()));
        $this->assertSame(0, $this->tenancy()->run($b, fn () => Category::query()->count()));
    }

    // ---------------------------------------------------------------- accounts

    public function test_a_new_shop_gets_a_cash_drawer(): void
    {
        $shop = $this->makeShop('aaa');

        $cash = $this->tenancy()->run($shop, fn () => Account::query()->get());

        $this->assertCount(1, $cash);
        $this->assertTrue($cash->first()->is_default_cash);
        $this->assertSame('cash', $cash->first()->type);
    }

    public function test_an_opening_balance_is_posted_to_the_ledger(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));

        Livewire::test('pages::shop.accounts')
            ->call('openCreate')
            ->set('name', 'Meezan Bank')
            ->set('type', 'bank')
            ->set('openingBalance', '25000.50')
            ->call('save')
            ->assertHasNoErrors();

        $account = Account::query()->where('name', 'Meezan Bank')->firstOrFail();
        $this->assertSame('25000.50', $account->current_balance);

        $txn = AccountTransaction::query()->where('account_id', $account->id)->sole();
        $this->assertSame('opening', $txn->type);
        $this->assertSame('25000.50', $txn->balance_after);
    }

    public function test_the_cash_drawer_cannot_be_deactivated_or_deleted(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $cash = Account::query()->where('is_default_cash', true)->firstOrFail();

        Livewire::test('pages::shop.accounts')
            ->call('toggleActive', $cash->id)
            ->call('delete', $cash->id);

        $this->assertTrue($cash->fresh()->is_active);
        $this->assertNotNull($cash->fresh());
    }

    // ---------------------------------------------------------------- menu

    public function test_a_product_with_sizes_and_add_ons_is_saved(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $category = Category::create(['name' => 'Burgers']);
        $extras = ModifierGroup::create(['name' => 'Extras']);
        $extras->options()->create(['name' => 'Cheese', 'price' => 100]);

        Livewire::test('pages::shop.menu.product-form')
            ->set('name', 'Zinger Burger')
            ->set('categoryId', (string) $category->id)
            ->set('variants', [
                ['id' => null, 'name' => 'Regular', 'price' => '650', 'is_available' => true],
                ['id' => null, 'name' => 'Large', 'price' => '850', 'is_available' => true],
            ])
            ->set('defaultVariant', 1)
            ->set('modifierGroupIds', [(string) $extras->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $product = Product::query()->with(['variants', 'modifierGroups'])->sole();

        $this->assertSame('AAA-P-0001', $product->sku);
        $this->assertSame(['Regular', 'Large'], $product->variants->pluck('name')->all());
        $this->assertSame(['650.00', '850.00'], $product->variants->pluck('price')->all());
        $this->assertSame('Large', $product->variants->firstWhere('is_default', true)->name);
        $this->assertSame([$extras->id], $product->modifierGroups->pluck('id')->all());
    }

    public function test_skus_are_numbered_per_shop(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');

        $skusA = $this->tenancy()->run($a, fn () => [$this->product('One', ['Regular' => 1])->sku, $this->product('Two', ['Regular' => 1])->sku]);
        $skuB = $this->tenancy()->run($b, fn () => $this->product('One', ['Regular' => 1])->sku);

        $this->assertSame(['AAA-P-0001', 'AAA-P-0002'], $skusA);
        $this->assertSame('BBB-P-0001', $skuB);
    }

    public function test_a_combo_deal_needs_items_and_saves_them(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $burger = $this->product('Burger', ['Regular' => 600]);
        $coke = $this->product('Coke', ['Regular' => 120]);

        $form = Livewire::test('pages::shop.menu.product-form')
            ->set('name', 'Deal 1')
            ->set('type', 'combo')
            ->set('variants.0.price', '1200')
            ->set('comboItems', [])
            ->call('save')
            ->assertHasErrors('comboItems');

        $form->set('comboItems', [
            ['variant_id' => (string) $burger->variants->first()->id, 'quantity' => '2'],
            ['variant_id' => (string) $coke->variants->first()->id, 'quantity' => '1'],
        ])->call('save')->assertHasNoErrors();

        $deal = Product::query()->where('type', 'combo')->sole();
        $this->assertSame([2, 1], $deal->comboItems->pluck('quantity')->all());
        $this->assertSame('1320.00', $form->instance()->comboValue);
    }

    public function test_a_size_used_in_a_deal_cannot_be_removed_and_its_product_cannot_be_archived(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $burger = $this->product('Burger', ['Regular' => 600, 'Large' => 800]);
        $deal = $this->product('Deal', ['Regular' => 1000], 'combo');
        ComboItem::create(['combo_product_id' => $deal->id, 'product_variant_id' => $burger->variants->last()->id, 'quantity' => 1]);

        Livewire::test('pages::shop.menu.product-form', ['product' => $burger])
            ->call('removeVariant', 1)
            ->call('save')
            ->assertHasErrors('variants');

        Livewire::test('pages::shop.menu.product-form', ['product' => $burger])->call('archive');

        $this->assertCount(2, $burger->fresh()->variants);
        $this->assertFalse($burger->fresh()->trashed());
    }

    public function test_categories_can_be_reordered_by_dragging(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $ids = collect(['Burgers', 'Fries', 'Drinks'])->map(fn ($name, $i) => Category::create(['name' => $name, 'sort_order' => $i + 1])->id);

        Livewire::test('pages::shop.menu.categories')->call('sort', $ids[2], 0);

        $this->assertSame(['Drinks', 'Burgers', 'Fries'], Category::query()->ordered()->pluck('name')->all());
    }

    // ---------------------------------------------------------------- staff & roles

    public function test_a_manager_cannot_create_or_change_owners(): void
    {
        $shop = $this->makeShop('aaa');
        $manager = $this->staff($shop, 'Manager');
        $manager->role->update(['permissions' => array_merge($manager->role->permissions, ['staff.manage'])]);
        $this->enter($shop, $manager->fresh());

        $ownerRole = Role::query()->where('is_locked', true)->sole();

        Livewire::test('pages::shop.staff')
            ->call('openCreate')
            ->set('name', 'Sneaky')
            ->set('email', 'sneaky@aaa.test')
            ->set('roleId', $ownerRole->id)
            ->call('save')
            ->assertHasErrors('roleId');

        Livewire::test('pages::shop.staff')
            ->call('toggleActive', $this->ownerOf($shop)->id)
            ->assertForbidden();

        $this->assertTrue($this->ownerOf($shop)->is_active);
    }

    public function test_a_custom_role_saves_only_known_permissions(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));

        Livewire::test('pages::shop.roles')
            ->call('openCreate')
            ->set('name', 'Supervisor')
            ->set('permissions', ['pos.use', 'orders.void'])
            ->call('toggleGroup', 'Money & reports')
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::query()->where('name', 'Supervisor')->sole();
        $this->assertEqualsCanonicalizing(
            ['pos.use', 'orders.void', 'accounts.manage', 'expenses.manage', 'reports.view'],
            $role->permissions,
        );

        Livewire::test('pages::shop.roles')
            ->call('openCreate')
            ->set('name', 'Hacker')
            ->set('permissions', ['everything'])
            ->call('save')
            ->assertHasErrors('permissions.0');
    }

    // ---------------------------------------------------------------- customers

    public function test_customer_phones_are_normalised_and_unique_per_shop(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $this->enter($a, $this->ownerOf($a));

        Livewire::test('pages::shop.customers')
            ->call('openCreate')->set('name', 'Ahmed')->set('phone', '+92 300-1234567')->call('save')->assertHasNoErrors();

        Livewire::test('pages::shop.customers')
            ->call('openCreate')->set('name', 'Duplicate')->set('phone', '0300 1234567')->call('save')->assertHasErrors('phone');

        $this->assertSame('03001234567', Customer::query()->sole()->phone);

        // The same number is fine in another shop.
        $this->enter($b, $this->ownerOf($b));
        Livewire::test('pages::shop.customers')
            ->call('openCreate')->set('name', 'Ahmed B')->set('phone', '03001234567')->call('save')->assertHasNoErrors();
    }

    // ---------------------------------------------------------------- helpers

    private function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** What the shop middleware does for a real request. */
    private function enter(Shop $shop, User $user): void
    {
        $this->tenancy()->set($shop);
        URL::defaults(['shop' => $shop->prefix]);
        $this->actingAs($user, 'shop');
    }

    private function staff(Shop $shop, string $role): User
    {
        return $this->tenancy()->run($shop, fn () => User::factory()->forShop($shop, $role)->create());
    }

    /** @param array<string, int|float> $prices */
    private function product(string $name, array $prices, string $type = 'simple'): Product
    {
        $product = Product::create(['name' => $name, 'type' => $type]);

        foreach (array_keys($prices) as $i => $variant) {
            $product->variants()->create(['name' => $variant, 'price' => $prices[$variant], 'is_default' => $i === 0, 'sort_order' => $i + 1]);
        }

        return $product->load('variants');
    }

    /** Sends a real Livewire update request for the page's root component. */
    private function livewireCall(TestResponse $page, string $method, array $params = [], array $updates = []): TestResponse
    {
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $match);

        return $this->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($match[1], ENT_QUOTES),
                'updates' => $updates,
                'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
            ]],
        ]);
    }
}
