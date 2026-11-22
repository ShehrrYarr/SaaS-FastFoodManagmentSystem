<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AccountLedger;
use App\Services\BusinessDays;
use App\Services\Orders;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** The M5 screens: expenses, account ledgers, transfers, reports and the CSV download. */
class MoneyPagesTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_the_owner_can_open_every_money_page(): void
    {
        $shop = $this->makeShop('aaa');
        $drawer = $this->drawerOf($shop);
        $this->actingAs($this->ownerOf($shop), 'shop');

        $this->get('/aaa/expenses')->assertOk()->assertSee('Add expense');
        $this->get('/aaa/reports')->assertOk()->assertSee('Nothing in this range');
        $this->get('/aaa/reports?range=custom&from=2026-01-01&to=2026-01-31')->assertOk();
        $this->get("/aaa/accounts/{$drawer->id}")->assertOk()->assertSee('Cash Drawer');
        $this->get('/aaa/dashboard')->assertOk()->assertSee('Sales, last 7 days')->assertSee('Accounts');
    }

    public function test_a_cashier_is_kept_out_of_money_pages_and_figures(): void
    {
        $shop = $this->makeShop('aaa');
        $drawer = $this->drawerOf($shop);
        $this->actingAs($this->staff($shop, 'Cashier'), 'shop');

        $this->get('/aaa/expenses')->assertForbidden();
        $this->get('/aaa/reports')->assertForbidden();
        $this->get('/aaa/reports/orders.csv?from=2026-01-01&to=2026-01-31')->assertForbidden();
        $this->get("/aaa/accounts/{$drawer->id}")->assertForbidden();
        $this->get('/aaa/dashboard')->assertOk()->assertDontSee('Sales, last 7 days')->assertDontSee('Profit (estimate)');
    }

    public function test_another_shops_account_ledger_is_not_found(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $drawerOfB = $this->drawerOf($b);

        $this->actingAs($this->ownerOf($a), 'shop');

        $this->get("/aaa/accounts/{$drawerOfB->id}")->assertNotFound();
    }

    public function test_recording_and_voiding_an_expense_from_the_page(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $drawer = $this->drawerOf($shop);
        app(AccountLedger::class)->post($drawer, 'opening', 2000);
        $rent = ExpenseCategory::query()->where('name', 'Rent')->sole();

        $page = Livewire::test('pages::shop.expenses')
            ->call('openCreate')
            ->assertSet('accountId', (string) $drawer->id)
            ->set('categoryId', (string) $rent->id)
            ->set('amount', '2500')
            ->call('save')
            ->assertSet('formError', 'Cash Drawer only has Rs 2,000.')
            ->set('amount', '1500')
            ->set('payee', 'Landlord')
            ->call('save')
            ->assertSet('showForm', false)
            ->assertSee('Landlord');

        $expense = Expense::query()->sole();
        $this->assertSame('500.00', $drawer->fresh()->current_balance);

        $page->call('startVoid', $expense->id)
            ->call('confirmVoid')
            ->assertHasErrors('voidReason')
            ->set('voidReason', 'Wrong month')
            ->call('confirmVoid')
            ->assertSet('showVoid', false)
            ->assertSee('Voided');

        $this->assertSame('2000.00', $drawer->fresh()->current_balance);
    }

    public function test_managing_expense_categories(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));

        Livewire::test('pages::shop.expenses')
            ->set('newCategory', 'Rent')
            ->call('addCategory')
            ->assertHasErrors('newCategory')
            ->set('newCategory', 'Generator diesel')
            ->call('addCategory')
            ->assertHasNoErrors();

        $diesel = ExpenseCategory::query()->where('name', 'Generator diesel')->sole();

        Livewire::test('pages::shop.expenses')
            ->call('toggleCategory', $diesel->id)
            ->call('deleteCategory', $diesel->id);

        $this->assertNull($diesel->fresh());
    }

    public function test_transfers_and_money_out_from_the_accounts_page(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $drawer = $this->drawerOf($shop);
        $bank = Account::create(['name' => 'Meezan', 'type' => 'bank']);
        app(AccountLedger::class)->post($drawer, 'opening', 3000);

        Livewire::test('accounts.move-money')
            ->dispatch('move-money', mode: 'transfer', account: $drawer->id)
            ->assertSet('fromId', (string) $drawer->id)
            ->assertSet('toId', (string) $bank->id)
            ->set('amount', '5000')
            ->call('save')
            ->assertHasErrors(['amount'])
            ->set('amount', '2500')
            ->call('save')
            ->assertSet('show', false)
            ->assertDispatched('money-moved');

        Livewire::test('accounts.move-money')
            ->dispatch('move-money', mode: 'out', account: $drawer->id)
            ->set('amount', '100')
            ->call('save')
            ->assertHasErrors(['note'])
            ->set('note', 'Owner drawing')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Transfer::query()->count());
        $this->assertSame('400.00', $drawer->fresh()->current_balance);
        $this->assertSame('2500.00', $bank->fresh()->current_balance);

        $this->get("/aaa/accounts/{$drawer->id}")->assertSee('Owner drawing')->assertSee('AAA-TRF-000001', false);
        $this->get("/aaa/accounts/{$drawer->id}?type=cash_out")->assertSee('Owner drawing')->assertDontSee('to Meezan');
    }

    public function test_the_report_and_csv_show_the_days_orders(): void
    {
        $shop = $this->makeShop('aaa');
        $this->enter($shop, $this->ownerOf($shop));
        $drawer = $this->drawerOf($shop);

        $product = Product::create(['name' => 'Zinger']);
        $variant = $product->variants()->create(['name' => 'Regular', 'price' => 650, 'is_default' => true]);

        $day = app(BusinessDays::class)->open(0);
        $orders = app(Orders::class);
        $order = $orders->send(null, ['type' => 'takeaway', 'customer_name' => '=HYPERLINK("http://x")'], [['variant_id' => $variant->id, 'quantity' => 2]])->order;
        $orders->checkout($order, [], [['account_id' => $drawer->id, 'amount' => 1300]]);

        $this->get('/aaa/reports?range=today')->assertOk()->assertSee('Zinger')->assertSee('Rs 1,300');
        $this->get('/aaa/dashboard')->assertOk()->assertSee('Rs 1,300');

        $today = $shop->today()->toDateString();
        $csv = $this->get("/aaa/reports/orders.csv?from={$today}&to={$today}");
        $csv->assertOk()->assertDownload('aaa-orders-'.$today.'-to-'.$today.'.csv');

        $body = $csv->streamedContent();
        $this->assertStringContainsString('AAA-000001', $body);
        $this->assertStringContainsString('1300.00', $body);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://x"")"', $body, 'Formulas are neutralised.');

        $this->get('/aaa/reports/orders.csv?from=2026-02-01&to=2026-01-01')->assertSessionHasErrors('to');
    }

    // ---------------------------------------------------------------- helpers

    private function enter(Shop $shop, User $user): void
    {
        app(TenantContext::class)->set($shop);
        URL::defaults(['shop' => $shop->prefix]);
        $this->actingAs($user, 'shop');
    }

    private function staff(Shop $shop, string $role): User
    {
        return app(TenantContext::class)->run($shop, fn () => User::factory()->forShop($shop, $role)->create());
    }

    private function drawerOf(Shop $shop): Account
    {
        return app(TenantContext::class)->run($shop, fn () => Account::query()->where('is_default_cash', true)->sole());
    }
}
