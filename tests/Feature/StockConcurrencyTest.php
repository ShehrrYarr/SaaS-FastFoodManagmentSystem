<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\BusinessDays;
use App\Services\Recipes;
use App\Services\Stock;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Several tills selling the same burger at the same moment. Each worker is a
 * separate PHP process with its own database connection, so the row locks are
 * really contended. Afterwards stock must equal start − sold exactly, the
 * ledger must be an unbroken chain, and order numbers and tokens unique.
 *
 * Data has to be committed for other processes to see it, so this test does
 * not use RefreshDatabase; it rebuilds the test database before and after.
 */
class StockConcurrencyTest extends TestCase
{
    use CreatesShops;

    private const WORKERS = 4;

    private const ORDERS_EACH = 8;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app->configurationIsCached()) {
            $this->markTestSkipped('Config is cached; worker processes would not use the test database.');
        }

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    public function test_parallel_tills_never_lose_a_stock_update(): void
    {
        $shop = $this->makeShop('blg', ['inventory_tracking_enabled' => true, 'allow_negative_stock' => true]);
        $owner = $this->ownerOf($shop);
        app(TenantContext::class)->set($shop);
        Auth::guard('shop')->setUser($owner);

        $burger = Product::create(['name' => 'Smash Burger']);
        $variant = $burger->variants()->create(['name' => 'Regular', 'price' => 900, 'is_default' => true]);
        $meat = Ingredient::create(['name' => 'Beef', 'unit' => 'kg']);
        $bun = Ingredient::create(['name' => 'Bun', 'unit' => 'pair']);
        app(Stock::class)->opening($meat, 50, 1500);
        app(Stock::class)->opening($bun, 500, 30);
        app(Recipes::class)->save($variant, [
            ['ingredient_id' => $meat->id, 'quantity' => 200, 'unit' => 'g'],
            ['ingredient_id' => $bun->id, 'quantity' => 1, 'unit' => 'pair'],
        ]);
        app(BusinessDays::class)->open(0);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'XDEBUG_MODE' => 'off',
        ];

        $workers = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Concurrency/send-orders.php'), $shop->id, $owner->id, $variant->id, self::ORDERS_EACH],
                base_path(),
                $env,
                null,
                180,
            );
            $process->start();
            $workers[] = $process;
        }

        foreach ($workers as $process) {
            $process->wait();
            $this->assertSame('ok '.self::ORDERS_EACH, trim($process->getOutput()), 'Worker failed: '.$process->getOutput().$process->getErrorOutput());
        }

        $sold = self::WORKERS * self::ORDERS_EACH;

        // Exact totals: 50 kg − 32 × 0.2 kg, 500 − 32 pairs.
        $this->assertSame('43.6000', $meat->fresh()->current_stock);
        $this->assertSame('468.0000', $bun->fresh()->current_stock);

        // The ledger is an unbroken chain: every balance is the previous one minus 0.2.
        $balances = StockMovement::query()->where('ingredient_id', $meat->id)->orderBy('id')->pluck('balance_after')->all();
        $this->assertCount($sold + 1, $balances);
        foreach (array_slice($balances, 1) as $i => $balance) {
            $this->assertSame(bcsub($balances[$i], '0.2', 4), $balance, 'Lost or doubled update at movement '.($i + 2));
        }

        // Numbers and tokens are unique and gap-free.
        $orders = Order::query()->orderBy('sequence')->get();
        $this->assertSame(range(1, $sold), $orders->pluck('sequence')->all());
        $this->assertSame(range(1, $sold), $orders->pluck('token_number')->sort()->values()->all());
    }
}
