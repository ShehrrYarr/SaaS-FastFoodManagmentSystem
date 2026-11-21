<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Kot;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\BusinessDays;
use App\Services\Orders;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The M3 screens: who can open them, tenant isolation of orders and print
 * views, and the POS component's own guards (server pricing, locked cart,
 * discount permission).
 */
class PosPagesTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private const PAGES = ['pos', 'tabs', 'orders', 'day'];

    public function test_owner_and_cashier_can_open_the_sales_pages(): void
    {
        $shop = $this->makeShop('aaa');

        foreach ([$this->ownerOf($shop), $this->staff($shop, 'Cashier')] as $user) {
            $this->actingAs($user, 'shop');

            foreach (self::PAGES as $page) {
                $this->get("/aaa/{$page}")->assertOk();
            }
        }
    }

    public function test_a_role_without_sales_permissions_is_kept_out(): void
    {
        $shop = $this->makeShop('aaa');
        $menuOnly = $this->tenancy()->run($shop, function () use ($shop) {
            $role = Role::create(['name' => 'Menu editor', 'permissions' => ['menu.manage']]);

            return User::factory()->forShop($shop, 'Cashier')->create(['role_id' => $role->id]);
        });

        $this->actingAs($menuOnly, 'shop');

        foreach (self::PAGES as $page) {
            $this->get("/aaa/{$page}")->assertForbidden();
        }
    }

    public function test_another_shops_orders_and_tickets_are_not_found(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');

        [$orderOfB, $kotOfB, $dayOfB] = $this->tenancy()->run($b, function () use ($b) {
            $this->actingAs($this->ownerOf($b), 'shop');
            $day = app(BusinessDays::class)->open(0);
            $kot = app(Orders::class)->send(null, ['type' => 'takeaway'], [$this->line($this->product('Burger', 500))]);

            return [$kot->order, $kot, $day];
        });

        $this->actingAs($this->ownerOf($a), 'shop');

        $this->get("/aaa/pos/{$orderOfB->id}")->assertNotFound();
        $this->get("/aaa/orders/{$orderOfB->id}")->assertNotFound();
        $this->get("/aaa/print/kot/{$kotOfB->id}")->assertNotFound();
        $this->get("/aaa/print/receipt/{$orderOfB->id}")->assertNotFound();
        $this->get("/aaa/print/day/{$dayOfB->id}")->assertNotFound();
        $this->get("/aaa/day/{$dayOfB->id}")->assertNotFound();

        // And Shop B's own owner sees them.
        $this->actingAs($this->ownerOf($b), 'shop');
        $this->get("/bbb/print/kot/{$kotOfB->id}")->assertOk()->assertSee('KOT #1');
        $this->assertSame(1, $this->tenancy()->run($b, fn () => Kot::query()->find($kotOfB->id)->printed_count));
    }

    public function test_print_views_need_a_sales_permission(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        app(BusinessDays::class)->open(0);
        $kot = app(Orders::class)->send(null, ['type' => 'takeaway'], [$this->line($this->product('Burger', 500))]);

        $menuOnly = User::factory()->forShop($shop, 'Cashier')->create([
            'role_id' => Role::create(['name' => 'Menu editor', 'permissions' => ['menu.manage']])->id,
        ]);

        $this->actingAs($menuOnly, 'shop')->get("/aaa/print/kot/{$kot->id}")->assertForbidden();
        $this->actingAs($this->staff($shop, 'Cashier'), 'shop')->get("/aaa/print/receipt/{$kot->order_id}")->assertOk()->assertSee('BILL · NOT PAID');
    }

    public function test_the_pos_asks_for_an_open_day(): void
    {
        $shop = $this->makeShop('aaa');
        $this->actingAs($this->ownerOf($shop), 'shop');

        $this->get('/aaa/pos')->assertOk()->assertSee("The business day isn't open");
    }

    public function test_the_pos_prices_lines_on_the_server_and_sends_them(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        app(BusinessDays::class)->open(0);
        $burger = $this->product('Burger', 500);
        $table = DiningTable::create(['name' => 'T-01']);

        Livewire::test('pages::shop.pos')
            ->call('addLine', $burger->variants->first()->id)
            ->call('addLine', $burger->variants->first()->id)
            ->assertSet('lines.0.quantity', 2)
            ->assertSet('lines.0.unit_price', '500.00')
            ->set('tableId', (string) $table->id)
            ->call('send')
            ->assertSet('lines', [])
            ->assertSet('orderId', null)
            ->assertDispatched('sfms-print');

        $order = Order::query()->sole();
        $this->assertSame('dine_in', $order->type);
        $this->assertSame($table->id, $order->dining_table_id);
        $this->assertSame('1000.00', $order->subtotal);
    }

    public function test_the_cart_cannot_be_edited_from_the_browser(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        app(BusinessDays::class)->open(0);
        $burger = $this->product('Burger', 500);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pages::shop.pos')
            ->call('addLine', $burger->variants->first()->id)
            ->set('lines.0.unit_price', '1.00');
    }

    public function test_a_cashier_cannot_give_a_discount_at_checkout(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->staff($shop, 'Cashier'));
        app(BusinessDays::class)->open(0);
        $order = app(Orders::class)->send(null, ['type' => 'takeaway'], [$this->line($this->product('Burger', 1000))])->order;
        $drawer = Account::query()->where('is_default_cash', true)->sole();

        Livewire::test('pages::shop.pos', ['order' => $order])
            ->call('openCheckout')
            ->assertSet('showCheckout', true)
            ->set('discountType', 'percent')
            ->set('discountValue', '50')
            ->set('payments', [['account_id' => (string) $drawer->id, 'amount' => '1000', 'tendered' => '', 'reference' => '']])
            ->call('completeCheckout')
            ->assertDispatched('pos-paid');

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertSame('1000.00', $order->grand_total);
    }

    public function test_a_paid_order_opens_its_history_instead_of_the_pos(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        app(BusinessDays::class)->open(0);
        $order = app(Orders::class)->send(null, ['type' => 'takeaway'], [$this->line($this->product('Burger', 500))])->order;
        app(Orders::class)->checkout($order, [], [['account_id' => Account::query()->where('is_default_cash', true)->value('id'), 'amount' => 500]]);

        $this->get("/aaa/pos/{$order->id}")->assertRedirect("/aaa/orders/{$order->id}");
    }

    // ---------------------------------------------------------------- helpers

    private function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

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

    private function product(string $name, int $price): Product
    {
        $product = Product::create(['name' => $name]);
        $product->variants()->create(['name' => 'Regular', 'price' => $price, 'is_default' => true]);

        return $product->load('variants');
    }

    private function line(Product $product): array
    {
        return ['variant_id' => $product->variants->first()->id, 'quantity' => 1];
    }
}
