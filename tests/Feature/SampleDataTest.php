<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BusinessDay;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleSalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M6 "done when": a fresh migrate --seed gives the Super Admin and the sample
 * shops, with sales history whose books balance. (Two days of history here to
 * keep the test quick; the real seed has two weeks.)
 */
class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    private int $days;

    protected function setUp(): void
    {
        parent::setUp();

        $this->days = SampleSalesSeeder::$historyDays;
        SampleSalesSeeder::$historyDays = 2;
    }

    protected function tearDown(): void
    {
        SampleSalesSeeder::$historyDays = $this->days;

        parent::tearDown();
    }

    public function test_seeding_gives_the_super_admin_and_sample_shops_with_balanced_books(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::query()->where('email', 'admin@sfms.test')->where('is_super_admin', true)->exists());
        $this->assertSame(['blg', 'ppj', 'ssd'], Shop::query()->orderBy('id')->pluck('prefix')->all());
        $this->assertSame(['active', 'expiring', 'suspended'], Shop::query()->orderBy('id')->get()->map->status()->all());

        foreach (['blg', 'ppj'] as $prefix) {
            app(TenantContext::class)->run(Shop::query()->where('prefix', $prefix)->sole(), function () use ($prefix) {
                // Two closed days and today open, with tabs running.
                $days = BusinessDay::query()->orderBy('id')->get();
                $this->assertSame(['closed', 'closed', 'open'], $days->pluck('status')->all(), $prefix);
                $this->assertSame(now(Shop::DEFAULT_TIMEZONE)->toDateString(), $days->last()->business_date->toDateString());
                $this->assertGreaterThan(10, Order::query()->where('status', 'completed')->count());
                $this->assertGreaterThan(0, Order::query()->open()->count());

                // Every closed day's cash is explained by the drawer's ledger.
                foreach ($days->where('status', 'closed') as $day) {
                    $this->assertSame('0.00', $day->summary['cash']['other'], "{$prefix} {$day->business_date->toDateString()}");
                }

                // Every running balance is unbroken and in time order.
                foreach (Account::query()->get() as $account) {
                    $balance = '0.00';
                    $entries = AccountTransaction::query()->where('account_id', $account->id)->orderBy('id')->get();
                    foreach ($entries as $entry) {
                        $balance = bcadd($balance, $entry->amount, 2);
                        $this->assertSame($balance, $entry->balance_after, "{$prefix} {$account->name} entry {$entry->id}");
                    }
                    $this->assertSame($balance, $account->current_balance);
                    $this->assertSame($entries->sortBy('transacted_at')->pluck('id')->values()->all(), $entries->pluck('id')->all());
                }

                $this->assertSame(0, Ingredient::query()->where('current_stock', '<', 0)->count());
            });
        }

        // Seeding again changes nothing.
        $orders = Order::query()->acrossShops()->count();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($orders, Order::query()->acrossShops()->count());
    }
}
