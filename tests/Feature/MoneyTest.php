<?php

namespace Tests\Feature;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Transfer;
use App\Services\AccountLedger;
use App\Services\AccountMoves;
use App\Services\BusinessDays;
use App\Services\Expenses;
use App\Services\Orders;
use App\Services\Reports;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Expenses, transfers, money in/out, and the M5 rule: at day close the
 * cash count can be checked line by line against the drawer's ledger.
 */
class MoneyTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $shop;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('blg');
        app(TenantContext::class)->set($this->shop);
        URL::defaults(['shop' => $this->shop->prefix]);
        $this->actingAs($this->ownerOf($this->shop), 'shop');

        $this->bank = Account::create(['name' => 'Meezan', 'type' => 'bank']);
    }

    public function test_new_shops_get_the_default_expense_categories(): void
    {
        $this->assertSame(ExpenseCategory::DEFAULTS, ExpenseCategory::query()->ordered()->pluck('name')->all());
    }

    // ---------------------------------------------------------------- expenses

    public function test_an_expense_takes_money_out_of_its_account_and_a_void_puts_it_back(): void
    {
        $this->fund($this->drawer(), 3000);

        $expense = $this->expenses()->record([
            'expense_category_id' => $this->category('Electricity')->id,
            'account_id' => $this->drawer()->id,
            'amount' => '1250.50',
            'payee' => 'K-Electric',
            'note' => 'November bill',
        ]);

        $this->assertSame('BLG-EXP-000001', $expense->voucher_number);
        $this->assertSame($this->shop->today()->toDateString(), $expense->spent_on->toDateString());
        $this->assertSame('1749.50', $this->drawer()->current_balance);

        $entry = AccountTransaction::query()->where('type', 'expense')->sole();
        $this->assertSame('-1250.50', $entry->amount);
        $this->assertTrue($entry->source->is($expense));
        $this->assertStringContainsString('K-Electric', $entry->note);

        $this->expenses()->void($expense, 'Entered twice');

        $this->assertSame('3000.00', $this->drawer()->current_balance);
        $this->assertNotNull($expense->fresh()->voided_at);
        $this->assertSame('Entered twice', $expense->fresh()->void_reason);
        $this->assertSame(0, Expense::query()->counted()->count());
        // The ledger keeps both lines: the expense and its reversal.
        $this->assertSame(['-1250.50', '1250.50'], AccountTransaction::query()->where('type', 'expense')->orderBy('id')->pluck('amount')->all());

        $this->expectExceptionMessage('already voided');
        $this->expenses()->void($expense, 'Again');
    }

    public function test_an_expense_cannot_take_more_than_the_account_holds(): void
    {
        $this->fund($this->drawer(), 500);

        try {
            $this->expenses()->record(['expense_category_id' => $this->category('Gas')->id, 'account_id' => $this->drawer()->id, 'amount' => 600]);
            $this->fail('The expense should have been refused.');
        } catch (PosException $e) {
            $this->assertSame('Cash Drawer only has Rs 500.', $e->getMessage());
        }

        $this->assertSame(0, Expense::query()->count());
        $this->assertSame('500.00', $this->drawer()->current_balance);

        // Nothing was used up: the next expense still gets the first number.
        $next = $this->expenses()->record(['expense_category_id' => $this->category('Gas')->id, 'account_id' => $this->drawer()->id, 'amount' => 500]);
        $this->assertSame('BLG-EXP-000001', $next->voucher_number);
        $this->assertSame('0.00', $this->drawer()->current_balance);
    }

    public function test_expense_rules(): void
    {
        $this->fund($this->drawer(), 1000);
        $base = ['expense_category_id' => $this->category('Rent')->id, 'account_id' => $this->drawer()->id, 'amount' => 100];

        $this->assertRefused(fn () => $this->expenses()->record(['amount' => 0] + $base), 'Enter the amount');
        $this->assertRefused(fn () => $this->expenses()->record(['spent_on' => $this->shop->today()->addDay()->toDateString()] + $base), 'future');
        $this->assertRefused(fn () => $this->expenses()->record(['expense_category_id' => null] + $base), 'Choose what the money was spent on');

        $this->bank->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->expenses()->record(['account_id' => $this->bank->id] + $base), 'active account');

        // Backdated expenses are fine; editing details never touches the money.
        $expense = $this->expenses()->record(['spent_on' => $this->shop->today()->subDays(3)->toDateString()] + $base);
        $this->expenses()->updateDetails($expense, ['expense_category_id' => $this->category('Gas')->id, 'payee' => 'SSGC', 'amount' => 99999]);

        $this->assertSame('Gas', $expense->fresh()->category->name);
        $this->assertSame('100.00', $expense->fresh()->amount);
        $this->assertSame('900.00', $this->drawer()->current_balance);
    }

    // ---------------------------------------------------------------- transfers & money in/out

    public function test_a_transfer_moves_money_between_two_accounts(): void
    {
        $this->fund($this->drawer(), 10000);

        $transfer = $this->moves()->transfer($this->drawer()->id, $this->bank->id, 7500, 'Deposit slip 4471');

        $this->assertSame('BLG-TRF-000001', $transfer->voucher_number);
        $this->assertSame('2500.00', $this->drawer()->current_balance);
        $this->assertSame('7500.00', $this->bank->fresh()->current_balance);

        $lines = AccountTransaction::query()->where('source_type', Transfer::class)->orderBy('id')->get();
        $this->assertSame(['transfer_out', 'transfer_in'], $lines->pluck('type')->all());
        $this->assertSame(['-7500.00', '7500.00'], $lines->pluck('amount')->all());
        $this->assertStringContainsString('to Meezan', $lines[0]->note);
        $this->assertStringContainsString('from Cash Drawer', $lines[1]->note);
    }

    public function test_transfer_rules(): void
    {
        $this->fund($this->drawer(), 1000);

        $this->assertRefused(fn () => $this->moves()->transfer($this->drawer()->id, $this->drawer()->id, 100), 'two different accounts');
        $this->assertRefused(fn () => $this->moves()->transfer($this->drawer()->id, $this->bank->id, 1000.01), 'Cash Drawer only has Rs 1,000');
        $this->assertRefused(fn () => $this->moves()->transfer($this->drawer()->id, $this->bank->id, 0), 'Enter the amount');

        $this->bank->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->moves()->transfer($this->drawer()->id, $this->bank->id, 100), 'must be active');

        // Another shop's account is simply not there.
        $other = $this->makeShop('ppj');
        $foreign = app(TenantContext::class)->run($other, fn () => Account::query()->where('is_default_cash', true)->sole());
        app(TenantContext::class)->set($this->shop);
        $this->assertRefused(fn () => $this->moves()->transfer($this->drawer()->id, $foreign->id, 100), 'must be active');

        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame('1000.00', $this->drawer()->current_balance);
    }

    public function test_money_in_and_out_need_a_reason_and_money_out_needs_the_balance(): void
    {
        $this->moves()->adjust($this->drawer()->id, 'in', 2000, 'Float from owner');
        $this->moves()->adjust($this->drawer()->id, 'out', 1500, 'Owner drawing');

        $this->assertSame('500.00', $this->drawer()->current_balance);
        $this->assertSame(['cash_in', 'cash_out'], AccountTransaction::query()->orderBy('id')->pluck('type')->all());

        $this->assertRefused(fn () => $this->moves()->adjust($this->drawer()->id, 'out', 501, 'Too much'), 'only has Rs 500');
        $this->assertRefused(fn () => $this->moves()->adjust($this->drawer()->id, 'in', 10, '  '), 'Say what the money was for');
    }

    // ---------------------------------------------------------------- day close

    public function test_the_cash_count_matches_the_drawer_ledger_at_day_close(): void
    {
        $burger = $this->product('Burger', 600);
        $this->fund($this->bank, 5000);

        // Drawer ledger says 0; 5,000 is counted in, so 5,000 is posted as over/short.
        $day = $this->days()->open(5000);

        $this->sell($burger, 3, $this->drawer());             // +1,800 cash
        $this->sell($burger, 2, $this->bank);                          // card/bank sale, not in the drawer
        $expense = $this->expenses()->record(['expense_category_id' => $this->category('Gas')->id, 'account_id' => $this->drawer()->id, 'amount' => 450]);
        $this->expenses()->record(['expense_category_id' => $this->category('Rent')->id, 'account_id' => $this->bank->id, 'amount' => 3000]);
        $this->moves()->transfer($this->drawer()->id, $this->bank->id, 4000, 'Banked');
        $this->moves()->transfer($this->bank->id, $this->drawer()->id, 1000, 'Change float');
        $this->moves()->adjust($this->drawer()->id, 'out', 300, 'Owner drawing');
        $this->moves()->adjust($this->drawer()->id, 'in', 50, 'Found under the till');
        $this->expenses()->void($expense, 'Paid by the owner instead');   // +450 back

        // 5,000 + 1,800 − 450 − 4,000 + 1,000 − 300 + 50 + 450 = 3,550
        $summary = $this->days()->summary($day->fresh());
        $this->assertSame('3550.00', $summary['cash']['expected']);
        $this->assertSame('3550.00', $this->drawer()->current_balance);
        $this->assertSame('1800.00', $summary['cash']['sales']);
        $this->assertSame('0.00', $summary['cash']['other'], 'Every rupee in the drawer is explained by the ledger.');
        $this->assertSame(
            ['Transfer in' => '1000.00', 'Transfer out' => '-4000.00', 'Expense' => '0.00', 'Cash in' => '50.00', 'Cash out' => '-300.00'],
            collect($summary['cash']['movements'])->pluck('amount', 'label')->all(),
        );
        $this->assertSame(
            $summary['cash']['expected'],
            Money::sum([$summary['cash']['opening'], $summary['cash']['sales'], ...array_column($summary['cash']['movements'], 'amount')]),
        );

        // Expenses of the day: the voided one no longer counts.
        $this->assertSame('3000.00', $summary['expenses']['amount']);
        $this->assertSame([['category' => 'Rent', 'count' => 1, 'amount' => '3000.00']], $summary['expenses']['by_category']);

        // Counting exactly the expected cash closes with no difference.
        $closed = $this->days()->close($day->fresh(), 3550);
        $this->assertSame('0.00', $closed->cash_difference);
        $this->assertSame('0.00', $closed->summary['cash']['other']);
        $this->assertSame(0, AccountTransaction::query()->where('type', 'over_short')->where('note', 'Closing cash count')->count());
        $this->assertSame('3550.00', AccountTransaction::query()->where('account_id', $this->drawer()->id)->latest('id')->value('balance_after'));
    }

    public function test_a_short_count_is_posted_and_the_stored_report_still_adds_up(): void
    {
        $burger = $this->product('Burger', 500);
        $day = $this->days()->open(1000);
        $this->sell($burger, 2, $this->drawer());
        $this->moves()->adjust($this->drawer()->id, 'out', 200, 'Ice');

        $closed = $this->days()->close($day, 1780);   // expected 1,800

        $this->assertSame('1800.00', $closed->expected_cash);
        $this->assertSame('-20.00', $closed->cash_difference);
        $this->assertSame('1780.00', $this->drawer()->current_balance);
        $this->assertSame('0.00', $closed->summary['cash']['other']);
        $this->assertSame([['label' => 'Cash out', 'entries' => 1, 'amount' => '-200.00']], $closed->summary['cash']['movements']);

        // Money moved after the close belongs to no day, and the next opening count absorbs it.
        $this->moves()->adjust($this->drawer()->id, 'out', 80, 'Night bill');
        $next = $this->days()->open(1700);
        $this->assertSame('1700.00', $next->opening_cash_expected);
        $this->assertSame('0.00', $this->days()->summary($next)['cash']['other']);
    }

    // ---------------------------------------------------------------- reports

    public function test_reports_add_up_sales_expenses_and_profit_by_business_date(): void
    {
        $burger = $this->product('Burger', 800);
        $this->fund($this->bank, 10000);

        $day = $this->days()->open(0);
        $this->sell($burger, 2, $this->drawer());
        $this->sell($burger, 1, $this->bank);
        $this->expenses()->record(['expense_category_id' => $this->category('Electricity')->id, 'account_id' => $this->bank->id, 'amount' => 700]);
        $this->expenses()->record(['expense_category_id' => $this->category('Gas')->id, 'account_id' => $this->bank->id, 'amount' => 300, 'spent_on' => $this->shop->today()->subMonthNoOverflow()->toDateString()]);
        $this->days()->close($day, 1600);

        $today = $this->shop->today()->toDateString();
        $report = app(Reports::class)->build($today, $today);

        $this->assertSame('2400.00', $report['sales']);
        $this->assertSame(2, $report['orders']);
        $this->assertSame('1200.00', $report['average']);
        $this->assertSame('700.00', $report['expenses']['amount']);       // the backdated one is last month
        $this->assertNull($report['cost']);                               // inventory is off
        $this->assertSame('1700.00', $report['profit']);
        $this->assertSame(['Cash Drawer' => '1600.00', 'Meezan' => '800.00'], collect($report['by_account'])->pluck('amount', 'account')->all());
        $this->assertSame([['name' => 'Burger', 'quantity' => 3, 'amount' => '2400.00']], $report['items']);
        $this->assertSame('2400.00', collect($report['trend']['points'])->sole()['sales']);
        $this->assertSame(2, collect($report['hours'])->sum('orders'));

        // A month-long range charts every date, quiet days as zero.
        $month = app(Reports::class)->build($this->shop->today()->startOfMonth()->toDateString(), $today);
        $this->assertCount($this->shop->today()->day, $month['trend']['points']);
        $this->assertSame('2400.00', $month['sales']);
    }

    // ---------------------------------------------------------------- helpers

    private function expenses(): Expenses
    {
        return app(Expenses::class);
    }

    private function moves(): AccountMoves
    {
        return app(AccountMoves::class);
    }

    private function days(): BusinessDays
    {
        return app(BusinessDays::class);
    }

    private function drawer(): Account
    {
        return Account::query()->where('is_default_cash', true)->sole();
    }

    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::query()->where('name', $name)->sole();
    }

    private function fund(Account $account, int $amount): void
    {
        app(AccountLedger::class)->post($account, 'opening', $amount);
    }

    private function product(string $name, int $price): Product
    {
        $product = Product::create(['name' => $name]);
        $product->variants()->create(['name' => 'Regular', 'price' => $price, 'is_default' => true]);

        return $product->load('variants');
    }

    private function sell(Product $product, int $quantity, Account $account): Order
    {
        $orders = app(Orders::class);
        $order = $orders->send(null, ['type' => 'takeaway'], [['variant_id' => $product->variants->first()->id, 'quantity' => $quantity]])->order;
        $total = $orders->preview($order->fresh(), [])->grand_total;

        return $orders->checkout($order, [], [['account_id' => $account->id, 'amount' => $total]]);
    }

    private function assertRefused(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail("Expected a refusal containing \"{$message}\".");
        } catch (PosException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
