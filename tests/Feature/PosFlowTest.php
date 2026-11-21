<?php

namespace Tests\Feature;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BusinessDay;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Waiter;
use App\Services\AccountLedger;
use App\Services\BusinessDays;
use App\Services\Orders;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The order cycle at service level: business day, send to kitchen, voids,
 * discounts and checkout for dine-in, takeaway and delivery.
 */
class PosFlowTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('blg', ['service_charge_type' => 'percent', 'service_charge_value' => 10, 'default_delivery_charge' => 150]);
        $this->enter($this->shop, $this->ownerOf($this->shop));
    }

    // ---------------------------------------------------------------- business day

    public function test_orders_need_an_open_business_day(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);

        $this->expectException(PosException::class);
        $this->expectExceptionMessage('The business day is not open');

        $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)]);
    }

    public function test_opening_the_day_posts_a_counted_difference_to_the_drawer(): void
    {
        $drawer = $this->drawer();
        app(AccountLedger::class)->post($drawer, 'opening', 5000);

        $day = $this->days()->open(4800, 'Short by 200');

        $this->assertSame('5000.00', $day->opening_cash_expected);
        $this->assertSame('4800.00', $day->opening_cash_counted);
        $this->assertSame('4800.00', $drawer->fresh()->current_balance);
        $this->assertSame('-200.00', AccountTransaction::query()->where('type', 'over_short')->sole()->amount);

        $this->expectExceptionMessage('already open');
        $this->days()->open(4800);
    }

    public function test_order_numbers_run_on_and_tokens_restart_each_day(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);

        $day = $this->days()->open(0);
        $a = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)])->order;
        $b = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)])->order;
        $this->checkoutCash($a);
        $this->checkoutCash($b);
        $this->days()->close($day, 1000);

        $this->days()->open(1000);
        $c = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)])->order;

        $this->assertSame(['BLG-000001', 'BLG-000002', 'BLG-000003'], [$a->order_number, $b->order_number, $c->order_number]);
        $this->assertSame([1, 2, 1], [$a->token_number, $b->token_number, $c->token_number]);
    }

    // ---------------------------------------------------------------- dine-in cycle

    public function test_full_dine_in_cycle_with_kots_void_and_split_payment(): void
    {
        $zinger = $this->product('Zinger', ['Regular' => 650, 'Large' => 850]);
        $fries = $this->product('Fries', ['Regular' => 300]);
        $extras = ModifierGroup::create(['name' => 'Extras']);
        $cheese = $extras->options()->create(['name' => 'Cheese', 'price' => 100]);
        $zinger->modifierGroups()->attach($extras->id);

        $table = DiningTable::create(['name' => 'T-01']);
        $waiter = Waiter::create(['name' => 'Ali']);
        $bank = Account::create(['name' => 'Meezan', 'type' => 'bank']);
        $this->days()->open(0);

        // First round: 2 x Large Zinger with cheese + 1 fries.
        $kot1 = $this->orders()->send(null, ['type' => 'dine_in', 'dining_table_id' => $table->id, 'waiter_id' => $waiter->id, 'guests' => 3], [
            $this->line($zinger, 'Large', [$cheese->id], 2),
            $this->line($fries),
        ]);
        $order = $kot1->order;

        $this->assertSame('new', $kot1->type);
        $this->assertSame(2, $kot1->items()->count());
        // (850 + 100) x 2 + 300 = 2200, + 10% service = 2420
        $this->assertSame('2200.00', $order->subtotal);
        $this->assertSame('220.00', $order->service_charge);
        $this->assertSame('2420.00', $order->grand_total);

        // The table is taken now.
        try {
            $this->orders()->send(null, ['type' => 'dine_in', 'dining_table_id' => $table->id], [$this->line($fries)]);
            $this->fail('A second tab opened on an occupied table.');
        } catch (PosException $e) {
            $this->assertStringContainsString('T-01 already has an open tab', $e->getMessage());
        }

        // Second round only prints the new line.
        $kot2 = $this->orders()->send($order, [], [$this->line($fries, quantity: 2)]);
        $this->assertSame(1, $kot2->items()->count());
        $this->assertSame(2, $kot2->number);

        // Void one fries with a reason: VOID KOT, totals drop by 300.
        $friesLine = $order->items()->where('kot_id', $kot2->id)->sole();
        $voidKot = $this->orders()->voidItem($friesLine, 1, 'Customer changed mind');
        $order->refresh();

        $this->assertSame('void', $voidKot->type);
        $this->assertSame(1, $friesLine->fresh()->voided_quantity);
        $this->assertSame('Customer changed mind', $voidKot->voids()->sole()->reason);
        $this->assertSame('2500.00', $order->subtotal);       // 2200 + 600 - 300
        $this->assertSame('2750.00', $order->grand_total);    // + 250 service

        // Split payment: 1000 by bank, 1750 cash (handed 2000).
        $paid = $this->orders()->checkout($order, [], [
            ['account_id' => $bank->id, 'amount' => 1000, 'reference' => 'TRX-1'],
            ['account_id' => $this->drawer()->id, 'amount' => 1750, 'tendered' => 2000],
        ]);

        $this->assertSame('completed', $paid->status);
        $this->assertSame('paid', $paid->payment_status);
        $this->assertSame('2750.00', $paid->paid_total);
        $this->assertSame('250.00', $paid->change_total);
        $this->assertSame('1000.00', $bank->fresh()->current_balance);
        $this->assertSame('1750.00', $this->drawer()->fresh()->current_balance);

        // Table is free again.
        $next = $this->orders()->send(null, ['type' => 'dine_in', 'dining_table_id' => $table->id], [$this->line($fries)])->order;
        $this->assertSame('open', $next->status);
    }

    public function test_payments_must_match_the_bill(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);
        $this->days()->open(0);
        $order = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)])->order;

        try {
            $this->orders()->checkout($order, [], [['account_id' => $this->drawer()->id, 'amount' => 400]]);
            $this->fail('A short payment was accepted.');
        } catch (PosException $e) {
            $this->assertStringContainsString('short of the bill', $e->getMessage());
        }

        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, AccountTransaction::query()->where('type', 'sale')->count());
    }

    public function test_discounts_and_waived_service_charge(): void
    {
        $burger = $this->product('Burger', ['Regular' => 1000]);
        $table = DiningTable::create(['name' => 'T-02']);
        $this->days()->open(0);

        $order = $this->orders()->send(null, ['type' => 'dine_in', 'dining_table_id' => $table->id], [
            $this->line($burger, quantity: 2) + ['discount_type' => 'fixed', 'discount_value' => 100],
        ])->order;

        // 2000 - 100 item discount = 1900; 10% off = 190 -> 1710; service waived.
        $preview = $this->orders()->preview($order, ['discount_type' => 'percent', 'discount_value' => 10, 'waive_service_charge' => true]);
        $this->assertSame('1710.00', $preview->grand_total);
        $this->assertSame('2090.00', $order->fresh()->grand_total, 'Preview must not change the saved order.');

        $paid = $this->checkoutCash($order, ['discount_type' => 'percent', 'discount_value' => 10, 'waive_service_charge' => true]);

        $this->assertSame('100.00', $paid->item_discount_total);
        $this->assertSame('190.00', $paid->discount_amount);
        $this->assertSame('0.00', $paid->service_charge);
        $this->assertSame('1710.00', $paid->grand_total);
    }

    // ---------------------------------------------------------------- takeaway & delivery

    public function test_delivery_remembers_the_customer_and_adds_the_delivery_charge(): void
    {
        $pizza = $this->product('Pizza', ['Medium' => 1200]);
        $rider = Waiter::create(['name' => 'Rashid', 'type' => 'rider']);
        $this->days()->open(0);

        $order = $this->orders()->send(null, [
            'type' => 'delivery',
            'customer_phone' => '+92 300-1234567',
            'customer_name' => 'Sara',
            'customer_address' => 'House 12, Gulberg',
        ], [$this->line($pizza)])->order;

        $this->assertSame('03001234567', $order->customer_phone);
        $this->assertSame('pending', $order->delivery_status);
        $this->assertSame('150.00', $order->delivery_charge);
        $this->assertSame('1350.00', $order->grand_total);
        $this->assertSame('0.00', $order->service_charge);

        $customer = Customer::query()->sole();
        $this->assertSame('Sara', $customer->name);

        $this->orders()->dispatchRider($order, $rider->id);
        $this->assertSame('out', $order->fresh()->delivery_status);

        $this->checkoutCash($order->fresh());

        $customer->refresh();
        $this->assertSame(1, $customer->orders_count);
        $this->assertSame('1350.00', $customer->total_spent);
        $this->assertSame('delivered', $order->fresh()->delivery_status);
    }

    public function test_delivery_needs_phone_and_address(): void
    {
        $pizza = $this->product('Pizza', ['Medium' => 1200]);
        $this->days()->open(0);

        $this->expectExceptionMessage('phone number');
        $this->orders()->send(null, ['type' => 'delivery', 'customer_address' => 'Somewhere'], [$this->line($pizza)]);
    }

    public function test_required_add_on_groups_are_enforced(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);
        $spice = ModifierGroup::create(['name' => 'Spice level', 'min_select' => 1, 'max_select' => 1]);
        $mild = $spice->options()->create(['name' => 'Mild']);
        $hot = $spice->options()->create(['name' => 'Hot']);
        $burger->modifierGroups()->attach($spice->id);
        $this->days()->open(0);

        foreach ([[], [$mild->id, $hot->id]] as $choice) {
            try {
                $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger, options: $choice)]);
                $this->fail('Bad add-on choice accepted.');
            } catch (PosException) {
                // expected
            }
        }

        $order = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger, options: [$hot->id])])->order;
        $this->assertSame(['Hot'], $order->items()->sole()->modifiers()->pluck('name')->all());
    }

    public function test_cancelling_voids_everything_and_frees_the_table(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);
        $table = DiningTable::create(['name' => 'T-03']);
        $this->days()->open(0);
        $order = $this->orders()->send(null, ['type' => 'dine_in', 'dining_table_id' => $table->id], [$this->line($burger, quantity: 3)])->order;

        $kot = $this->orders()->cancel($order, 'Guests left');
        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame(3, $kot->voids()->sole()->quantity);
        $this->assertSame(0, Order::query()->open()->where('dining_table_id', $table->id)->count());
    }

    public function test_closing_the_day_compares_counted_cash_with_the_drawer(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);
        $day = $this->days()->open(0);
        $order = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger, quantity: 3)])->order;
        $this->checkoutCash($order);

        // Expected 1500 in the drawer; 1450 counted.
        $closed = $this->days()->close($day, 1450, 'Short 50');

        $this->assertSame('closed', $closed->status);
        $this->assertSame('1500.00', $closed->expected_cash);
        $this->assertSame('-50.00', $closed->cash_difference);
        $this->assertSame('1450.00', $this->drawer()->fresh()->current_balance);
        $this->assertSame('1500.00', $closed->summary['sales']);
        $this->assertSame(1, $closed->summary['orders']);
        $this->assertSame([['name' => 'Burger', 'quantity' => 3, 'amount' => '1500.00']], $closed->summary['items']);
        $this->assertNull($this->days()->current());
    }

    public function test_shops_never_see_each_others_orders(): void
    {
        $burger = $this->product('Burger', ['Regular' => 500]);
        $this->days()->open(0);
        $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)]);

        $other = $this->makeShop('ppj');
        $this->tenancy()->run($other, function () {
            $this->assertSame(0, Order::query()->count());
            $this->assertNull(app(BusinessDays::class)->current());
        });
    }

    // ---------------------------------------------------------------- helpers

    private function orders(): Orders
    {
        return app(Orders::class);
    }

    private function days(): BusinessDays
    {
        return app(BusinessDays::class);
    }

    private function drawer(): Account
    {
        return Account::query()->where('is_default_cash', true)->sole();
    }

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

    /** @param array<string, int|float> $prices */
    private function product(string $name, array $prices): Product
    {
        $product = Product::create(['name' => $name]);

        foreach (array_keys($prices) as $i => $variant) {
            $product->variants()->create(['name' => $variant, 'price' => $prices[$variant], 'is_default' => $i === 0, 'sort_order' => $i + 1]);
        }

        return $product->load('variants');
    }

    private function line(Product $product, ?string $variant = null, array $options = [], int $quantity = 1): array
    {
        $variant = $variant ? $product->variants->firstWhere('name', $variant) : $product->variants->first();

        return ['variant_id' => $variant->id, 'option_ids' => $options, 'quantity' => $quantity];
    }

    private function checkoutCash(Order $order, array $adjustments = []): Order
    {
        $total = $this->orders()->preview($order->fresh(), $adjustments)->grand_total;

        return $this->orders()->checkout($order, $adjustments, [['account_id' => $this->drawer()->id, 'amount' => $total]]);
    }
}
