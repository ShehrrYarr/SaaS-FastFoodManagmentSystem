<?php

namespace Tests\Feature;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AccountLedger;
use App\Services\BusinessDays;
use App\Services\Orders;
use App\Services\Purchases;
use App\Services\Recipes;
use App\Services\Stock;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * M4: recipes take stock when items go to the kitchen, voids give it back,
 * purchases add it at a weighted average cost, and the shop's negative-stock
 * rule is respected.
 */
class InventoryTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('blg', ['inventory_tracking_enabled' => true, 'allow_negative_stock' => true]);
        $this->enter($this->shop, $this->ownerOf($this->shop));
    }

    // ---------------------------------------------------------------- units

    public function test_units_convert_only_within_their_family(): void
    {
        $this->assertSame('0.2000', Quantity::convert(200, 'g', 'kg'));
        $this->assertSame('1500.0000', Quantity::convert('1.5', 'L', 'ml'));
        $this->assertSame('24.0000', Quantity::convert(2, 'dozen', 'pcs'));
        $this->assertSame('2.0000', Quantity::convert(1, 'pair', 'pcs'));
        $this->assertSame(['kg', 'g'], Quantity::compatible('kg'));

        $this->expectException(InvalidArgumentException::class);
        Quantity::convert(1, 'kg', 'L');
    }

    // ---------------------------------------------------------------- the burger example (spec)

    public function test_a_burger_takes_exactly_its_recipe_from_stock(): void
    {
        [$burger, $meat, $sauce, $bun] = $this->burgerSetup();
        $this->days()->open(0);

        $kot = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)]);

        $this->assertSame('9.8000', $meat->fresh()->current_stock);   // 10 kg − 200 g
        $this->assertSame('4.9000', $sauce->fresh()->current_stock);  // 5 kg − 100 g
        $this->assertSame('49.0000', $bun->fresh()->current_stock);   // 50 pair − 1 pair

        $movements = StockMovement::query()->where('type', 'sale')->orderBy('ingredient_id')->get();
        $this->assertSame(['-0.2000', '-0.1000', '-1.0000'], $movements->pluck('quantity')->all());
        $this->assertTrue($movements->every(fn ($m) => $m->source_type === $kot->getMorphClass() && $m->source_id === $kot->id));
        $this->assertSame([$meat->id => '0.2000', $sauce->id => '0.1000', $bun->id => '1.0000'], $kot->items()->sole()->stock_snapshot);
    }

    public function test_deals_and_add_ons_use_their_own_recipes_too(): void
    {
        [$burger, $meat, , $bun] = $this->burgerSetup();
        $cheese = $this->ingredient('Cheese slice', 'pcs', 100);
        $extras = ModifierGroup::create(['name' => 'Extras']);
        $extraCheese = $extras->options()->create(['name' => 'Extra Cheese', 'price' => 100]);
        app(Recipes::class)->save($extraCheese, [['ingredient_id' => $cheese->id, 'quantity' => 1, 'unit' => 'pcs']]);

        $deal = Product::create(['name' => 'Burger Duo', 'type' => 'combo']);
        $deal->variants()->create(['name' => 'Regular', 'price' => 1500, 'is_default' => true]);
        $deal->comboItems()->create(['product_variant_id' => $burger->variants->first()->id, 'quantity' => 2]);
        $deal->modifierGroups()->attach($extras->id);
        $deal->load('variants');

        $this->days()->open(0);
        // 3 deals with extra cheese: 6 burgers' worth + 3 cheese slices.
        $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($deal, [$extraCheese->id], 3)]);

        $this->assertSame('8.8000', $meat->fresh()->current_stock);  // 10 − 6 × 0.2
        $this->assertSame('44.0000', $bun->fresh()->current_stock);  // 50 − 6
        $this->assertSame('97.0000', $cheese->fresh()->current_stock);
    }

    public function test_voids_and_cancels_give_back_what_was_taken_even_after_a_recipe_change(): void
    {
        [$burger, $meat] = $this->burgerSetup();
        $this->days()->open(0);
        $order = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger, quantity: 3)])->order;
        $this->assertSame('9.4000', $meat->fresh()->current_stock);

        // The recipe changes afterwards; the void must still return 200 g per burger.
        app(Recipes::class)->save($burger->variants->first(), [['ingredient_id' => $meat->id, 'quantity' => 500, 'unit' => 'g']]);

        $this->orders()->voidItem($order->items()->sole(), 1, 'Customer changed mind');
        $this->assertSame('9.6000', $meat->fresh()->current_stock);

        $this->orders()->cancel($order->fresh(), 'Left');
        $this->assertSame('10.0000', $meat->fresh()->current_stock);
        $this->assertSame(2, StockMovement::query()->where('type', 'void_return')->where('ingredient_id', $meat->id)->count());
    }

    public function test_a_shop_that_blocks_negative_stock_refuses_the_whole_send(): void
    {
        $this->shop->update(['allow_negative_stock' => false]);
        [$burger, $meat, , $bun] = $this->burgerSetup(meat: '0.3');
        $this->days()->open(0);

        try {
            $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger, quantity: 2)]);
            $this->fail('Sent with too little stock.');
        } catch (PosException $e) {
            $this->assertStringContainsString('Not enough Beef patty mince', $e->getMessage());
            $this->assertStringContainsString('0.3 kg left, 0.4 kg needed', $e->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame('0.3000', $meat->fresh()->current_stock);
        $this->assertSame('50.0000', $bun->fresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->where('type', 'sale')->count());

        // One burger fits.
        $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)]);
        $this->assertSame('0.1000', $meat->fresh()->current_stock);
    }

    public function test_a_shop_that_allows_negative_stock_sells_on_with_a_warning(): void
    {
        [$burger, $meat] = $this->burgerSetup(meat: '0.3');
        $meat->update(['low_stock_threshold' => 1]);
        $this->days()->open(0);

        $kot = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger, quantity: 2)]);

        $this->assertSame('-0.1000', $meat->fresh()->current_stock);
        $this->assertSame(['Beef patty mince is below zero (−0.1 kg)'], $kot->stockWarnings);
    }

    public function test_nothing_is_taken_while_tracking_is_off(): void
    {
        $this->shop->update(['inventory_tracking_enabled' => false]);
        [$burger, $meat] = $this->burgerSetup();
        $this->days()->open(0);

        $kot = $this->orders()->send(null, ['type' => 'takeaway'], [$this->line($burger)]);

        $this->assertSame('10.0000', $meat->fresh()->current_stock);
        $this->assertNull($kot->items()->sole()->stock_snapshot);
    }

    // ---------------------------------------------------------------- purchases & adjustments

    public function test_a_purchase_adds_stock_at_a_weighted_average_cost_and_pays_from_the_account(): void
    {
        $chicken = $this->ingredient('Chicken', 'kg', 10, 900);   // 10 kg @ 900
        $buns = $this->ingredient('Buns', 'pcs', 0);
        $bank = Account::create(['name' => 'Meezan', 'type' => 'bank']);
        app(AccountLedger::class)->post($bank, 'opening', 10000);

        $purchase = app(Purchases::class)->record(['supplier_name' => 'Poultry Co', 'account_id' => $bank->id], [
            ['ingredient_id' => $chicken->id, 'quantity' => 5000, 'unit' => 'g', 'line_total' => 5250],  // 5 kg @ 1050
            ['ingredient_id' => $buns->id, 'quantity' => 2, 'unit' => 'dozen', 'line_total' => 600],
        ]);

        $this->assertSame('BLG-PUR-000001', $purchase->voucher_number);
        $this->assertSame('5850.00', $purchase->total);
        $this->assertSame('15.0000', $chicken->fresh()->current_stock);
        $this->assertSame('950.0000', $chicken->fresh()->avg_cost);         // (10×900 + 5250) / 15
        $this->assertSame('24.0000', $buns->fresh()->current_stock);
        $this->assertSame('25.0000', $buns->fresh()->avg_cost);
        $this->assertSame('4150.00', $bank->fresh()->current_balance);

        // Unpaid: stock in, no money out.
        app(Purchases::class)->record(['account_id' => null], [['ingredient_id' => $buns->id, 'quantity' => 12, 'unit' => 'pcs', 'line_total' => 300]]);
        $this->assertSame('4150.00', $bank->fresh()->current_balance);
        $this->assertSame('36.0000', $buns->fresh()->current_stock);
    }

    public function test_a_purchase_rejects_a_unit_from_another_family(): void
    {
        $oil = $this->ingredient('Oil', 'L', 0);

        $this->expectException(PosException::class);
        $this->expectExceptionMessage('Oil is counted in L');

        app(Purchases::class)->record([], [['ingredient_id' => $oil->id, 'quantity' => 5, 'unit' => 'kg', 'line_total' => 2000]]);
    }

    public function test_counts_and_wastage_are_recorded_in_the_ledger(): void
    {
        $fries = $this->ingredient('Fries', 'kg', 20);

        app(Stock::class)->count($fries, '18.5', 'Weekly count');
        app(Stock::class)->waste($fries, '0.5', 'Dropped a bag');

        $this->assertSame('18.0000', $fries->fresh()->current_stock);
        $this->assertSame(
            [['opening', '20.0000', '20.0000'], ['adjustment', '-1.5000', '18.5000'], ['wastage', '-0.5000', '18.0000']],
            StockMovement::query()->orderBy('id')->get()->map(fn ($m) => [$m->type, $m->quantity, $m->balance_after])->all(),
        );
    }

    public function test_recipes_reject_duplicates_and_wrong_units(): void
    {
        [$burger, $meat] = $this->burgerSetup();
        $variant = $burger->variants->first();

        foreach ([
            [['ingredient_id' => $meat->id, 'quantity' => 1, 'unit' => 'kg'], ['ingredient_id' => $meat->id, 'quantity' => 2, 'unit' => 'g']],
            [['ingredient_id' => $meat->id, 'quantity' => 1, 'unit' => 'pcs']],
            [['ingredient_id' => $meat->id, 'quantity' => 0, 'unit' => 'g']],
        ] as $rows) {
            try {
                app(Recipes::class)->save($variant, $rows);
                $this->fail('Bad recipe saved.');
            } catch (PosException) {
                // expected
            }
        }

        $this->assertSame(3, $variant->recipeItems()->count(), 'The old recipe stays when a save fails.');
    }

    // ---------------------------------------------------------------- pages

    public function test_inventory_pages_open_for_the_owner_and_redirect_when_tracking_is_off(): void
    {
        $this->burgerSetup();

        foreach (['ingredients', 'recipes', 'purchases', 'purchases/create', 'stock-ledger'] as $page) {
            $this->get("/blg/{$page}")->assertOk();
        }

        $this->shop->update(['inventory_tracking_enabled' => false]);
        $this->get('/blg/ingredients')->assertRedirect('/blg/dashboard');
    }

    public function test_a_cashier_cannot_open_inventory_pages(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop, 'Cashier')->create(), 'shop');

        foreach (['ingredients', 'recipes', 'purchases', 'stock-ledger'] as $page) {
            $this->get("/blg/{$page}")->assertForbidden();
        }
    }

    public function test_shops_never_see_each_others_ingredients(): void
    {
        $this->ingredient('Secret sauce', 'kg', 3);
        $other = $this->makeShop('ppj', ['inventory_tracking_enabled' => true]);

        $this->tenancy()->run($other, function () {
            $this->assertSame(0, Ingredient::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
        });

        $this->actingAs($this->ownerOf($other), 'shop')->get('/ppj/ingredients')->assertOk()->assertDontSee('Secret sauce');
    }

    // ---------------------------------------------------------------- helpers

    /** Burger: 200 g meat (kg), 100 g sauce (kg), 1 pair bun (pair). */
    private function burgerSetup(string $meat = '10'): array
    {
        $burger = Product::create(['name' => 'Smash Burger']);
        $burger->variants()->create(['name' => 'Regular', 'price' => 900, 'is_default' => true]);
        $burger->load('variants');

        $meatIngredient = $this->ingredient('Beef patty mince', 'kg', $meat);
        $sauce = $this->ingredient('Burger sauce', 'kg', 5);
        $bun = $this->ingredient('Sesame bun', 'pair', 50);

        app(Recipes::class)->save($burger->variants->first(), [
            ['ingredient_id' => $meatIngredient->id, 'quantity' => 200, 'unit' => 'g'],
            ['ingredient_id' => $sauce->id, 'quantity' => 100, 'unit' => 'g'],
            ['ingredient_id' => $bun->id, 'quantity' => 1, 'unit' => 'pair'],
        ]);

        return [$burger, $meatIngredient, $sauce, $bun];
    }

    private function ingredient(string $name, string $unit, string|int $stock, int $cost = 0): Ingredient
    {
        $ingredient = Ingredient::create(['name' => $name, 'unit' => $unit]);
        app(Stock::class)->opening($ingredient, $stock, $cost);

        return $ingredient->fresh();
    }

    private function line(Product $product, array $options = [], int $quantity = 1): array
    {
        return ['variant_id' => $product->variants->first()->id, 'option_ids' => $options, 'quantity' => $quantity];
    }

    private function orders(): Orders
    {
        return app(Orders::class);
    }

    private function days(): BusinessDays
    {
        return app(BusinessDays::class);
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
}
