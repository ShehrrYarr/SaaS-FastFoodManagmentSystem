<?php

namespace Database\Seeders;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\BusinessDay;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Waiter;
use App\Services\AccountMoves;
use App\Services\BusinessDays;
use App\Services\Expenses;
use App\Services\Orders;
use App\Services\Purchases;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use stdClass;

/**
 * Two weeks of trading for the demo shops, so the dashboard, reports, account
 * ledgers and day reports have something to show.
 *
 * Every past day is opened with a cash count, sells through the real services
 * (orders, kitchen tickets, stock, payments), records expenses, purchases and
 * transfers, and is closed with a count, all with the clock set to that moment,
 * so ledgers, stock and Z reports agree exactly. Today is left open with a few
 * tabs running.
 *
 * Deterministic (fixed random seed per shop). Skips a shop that already has orders.
 */
class SampleSalesSeeder extends Seeder
{
    /** Closed trading days before today (tests use fewer to stay quick). */
    public static int $historyDays = 14;

    /** prefix => random seed */
    private const SHOPS = ['blg' => 7, 'ppj' => 19];

    private const FLOAT = 5000;

    private const NAMES = ['Ahmed', 'Sara', 'Usman', 'Hira', 'Bilal', 'Zara', 'Omar', 'Ayesha', 'Hamza', 'Maham'];

    private const SUPPLIERS = ['Al-Madina Traders', 'K&N Wholesale', 'Metro Cash & Carry', 'Fresh Farms'];

    private Shop $shop;

    /** @var array{owner: User, manager: User, cashier: User} */
    private array $staff;

    private Account $drawer;

    private ?BusinessDay $day = null;

    private Collection $products;

    private Collection $tables;

    private Collection $waiters;

    private Collection $riders;

    private Collection $customers;

    private int $dayIndex = 0;

    /** @var list<array{at: Carbon, seq: int, run: Closure}> */
    private array $events = [];

    private int $seq = 0;

    public function __construct(
        private TenantContext $tenancy,
        private BusinessDays $days,
        private Orders $orders,
        private Expenses $expenses,
        private AccountMoves $moves,
        private Purchases $purchases,
    ) {}

    public function run(): void
    {
        foreach (self::SHOPS as $prefix => $seed) {
            $shop = Shop::query()->where('prefix', $prefix)->first();

            if (! $shop) {
                continue;
            }

            $this->tenancy->run($shop, function (Shop $shop) use ($seed) {
                if (Order::query()->exists() || BusinessDay::query()->exists()) {
                    return;
                }

                mt_srand($seed);
                $this->load($shop);

                $today = $shop->today();
                for ($ago = self::$historyDays; $ago >= 1; $ago--) {
                    $this->dayIndex = self::$historyDays - $ago;
                    $this->pastDay($today->copy()->subDays($ago));
                }

                $this->today();
            });
        }

        Carbon::setTestNow();
        Auth::guard('shop')->forgetUser();
    }

    // ---------------------------------------------------------------- days

    private function pastDay(Carbon $date): void
    {
        $at = fn (string $time) => Carbon::parse($date->toDateString().' '.$time, $this->shop->timezone);

        $this->add($at('11:00'), fn () => $this->openDay());

        if ($this->shop->inventory_tracking_enabled) {
            $this->add($at('11:20'), fn () => $this->restock());
        }

        $orders = $date->isWeekend() ? mt_rand(24, 32) : mt_rand(15, 22);
        for ($i = 0; $i < $orders; $i++) {
            $this->scheduleOrder($date->copy()->startOfDay()->addMinutes($this->orderMinute()), $at('23:40'));
        }

        $this->scheduleExpenses($at);

        if ($this->dayIndex % 2 === 1) {
            $this->add($at('22:45'), fn () => $this->bankTheCash());
        }
        if ($this->dayIndex % 7 === 6) {
            $this->add($at('22:50'), fn () => $this->sweepWallets());
            $this->add($at('22:55'), fn () => $this->ownerDrawing());
        }

        $this->add($at('23:50'), fn () => $this->closeDay());
        $this->play();
    }

    /** Today: open a little while ago, a few orders paid and some tabs still running. */
    private function today(): void
    {
        $now = Carbon::now($this->shop->timezone);
        $start = $now->copy()->subMinutes(150)->max($now->copy()->startOfDay()->addMinutes(5));
        $span = max(10, (int) $start->diffInMinutes($now) - 5);

        $this->add($start, fn () => $this->openDay(exact: true));

        foreach ([0.15, 0.3, 0.45, 0.6] as $share) {
            $this->scheduleOrder($start->copy()->addMinutes((int) ($span * $share)), $now->copy()->subMinute(), payWithin: 0.2 * $span);
        }

        // Still open: two tables, a takeaway, and a delivery out with the rider.
        foreach (['dine_in', 'dine_in', 'takeaway', 'delivery'] as $i => $type) {
            $sendAt = $start->copy()->addMinutes((int) ($span * (0.7 + 0.07 * $i)));
            $this->add($sendAt, function () use ($type) {
                $order = $this->sendOrder(['type' => $type, 'lines' => $this->randomLines(mt_rand(1, 3))]);
                if ($order && $type === 'delivery' && $this->riders->isNotEmpty()) {
                    $this->as('cashier');
                    $this->attempt(fn () => $this->orders->dispatchRider($order, $this->riders->random()->id));
                }
            });
        }

        $this->play();
    }

    private function openDay(bool $exact = false): void
    {
        $this->as('owner');
        $expected = Money::of($this->drawer->fresh()->current_balance);

        // Now and then the morning count is a little short.
        $counted = ! $exact && mt_rand(1, 10) === 1
            ? Money::max(0, Money::sub($expected, mt_rand(1, 5) * 10))
            : $expected;

        $this->day = $this->days->open($counted, $counted === $expected ? null : 'Recounted, a little short');
    }

    private function closeDay(): void
    {
        // Nothing carries over from the sample past: settle any tab still open.
        foreach (Order::query()->open()->get() as $order) {
            $this->settle($order);
        }

        $this->as('owner');
        $expected = Money::of($this->drawer->fresh()->current_balance);

        $roll = mt_rand(1, 12);
        $counted = match (true) {
            $roll === 1 => Money::max(0, Money::sub($expected, mt_rand(1, 15) * 10)),
            $roll === 2 => Money::add($expected, mt_rand(1, 5) * 10),
            default => $expected,
        };

        $this->days->close($this->day->fresh(), $counted, $counted === $expected ? null : 'Counted twice');
        $this->day = null;
    }

    // ---------------------------------------------------------------- orders

    private function scheduleOrder(Carbon $sendAt, Carbon $latest, ?float $payWithin = null): void
    {
        $plan = [
            'type' => $this->pick(['dine_in' => 45, 'takeaway' => 35, 'delivery' => $this->riders->isNotEmpty() ? 20 : 0]),
            'lines' => $this->randomLines($this->pick([1 => 35, 2 => 35, 3 => 20, 4 => 10])),
        ];
        $holder = new stdClass;
        $holder->order = null;

        $this->add($sendAt, function () use ($holder, $plan) {
            $holder->order = $this->sendOrder($plan);
        });

        $minutes = $payWithin ?? match ($plan['type']) {
            'dine_in' => mt_rand(35, 70),
            'delivery' => mt_rand(30, 50),
            default => mt_rand(5, 15),
        };

        if ($plan['type'] === 'dine_in' && $payWithin === null && mt_rand(1, 100) <= 30) {
            $this->add($sendAt->copy()->addMinutes(mt_rand(10, 25)), function () use ($holder) {
                if ($holder->order) {
                    $this->as('cashier');
                    $this->attempt(fn () => $this->orders->send($holder->order->fresh(), [], $this->randomLines(mt_rand(1, 2))));
                }
            });
        }

        if ($plan['type'] === 'delivery') {
            $this->add($sendAt->copy()->addMinutes(max(1, (int) ($minutes / 3))), function () use ($holder) {
                if ($holder->order && $this->riders->isNotEmpty()) {
                    $this->as('cashier');
                    $this->attempt(fn () => $this->orders->dispatchRider($holder->order->fresh(), $this->riders->random()->id));
                }
            });
        }

        $this->add($sendAt->copy()->addMinutes((int) max(1, $minutes))->min($latest), function () use ($holder) {
            if ($holder->order) {
                $this->settle($holder->order);
            }
        });
    }

    private function sendOrder(array $plan): ?Order
    {
        $this->as('cashier');
        $type = $plan['type'];
        $details = ['type' => $type];

        if ($type === 'dine_in') {
            $busy = Order::query()->open()->whereNotNull('dining_table_id')->pluck('dining_table_id')->all();
            $table = $this->tables->whereNotIn('id', $busy)->shuffle()->first();

            if (! $table) {
                $type = $details['type'] = 'takeaway';
            } else {
                $details += [
                    'dining_table_id' => $table->id,
                    'waiter_id' => $this->waiters->isNotEmpty() ? $this->waiters->random()->id : null,
                    'guests' => mt_rand(1, max(1, (int) $table->capacity)),
                ];
            }
        }

        if ($type === 'takeaway' && mt_rand(1, 100) <= 30) {
            $details['customer_name'] = self::NAMES[array_rand(self::NAMES)];
        }

        if ($type === 'delivery') {
            $customer = $this->customers->random();
            $details += [
                'customer_phone' => $customer->phone,
                'customer_name' => $customer->name,
                'customer_address' => $customer->address ?: 'House 12, Street 4',
            ];
        }

        return $this->attempt(fn () => $this->orders->send(null, $details, $plan['lines'])->order);
    }

    /** Pay (or now and then void a line, give a discount, or cancel). */
    private function settle(Order $order): void
    {
        $order = $order->fresh();

        if (! $order?->isOpen()) {
            return;
        }

        if (mt_rand(1, 100) <= 3) {
            $this->as('manager');
            $this->attempt(fn () => $this->orders->cancel($order, ['Customer left', 'Ordered by mistake', 'Took too long'][mt_rand(0, 2)]));

            return;
        }

        if (mt_rand(1, 100) <= 5) {
            $item = OrderItem::query()->where('order_id', $order->id)->get()->first(fn (OrderItem $i) => $i->activeQuantity() > 0);
            $this->as('cashier');
            if ($item && $order->items()->count() > 1) {
                $this->attempt(fn () => $this->orders->voidItem($item, 1, ['Customer changed mind', 'Wrong item entered', 'Kitchen mistake'][mt_rand(0, 2)]));
            }
        }

        $adjustments = [];
        $this->as('cashier');
        if (mt_rand(1, 100) <= 8) {
            $adjustments = ['discount_type' => 'percent', 'discount_value' => [5, 10, 15][mt_rand(0, 2)]];
            $this->as('manager');
        }

        $total = $this->attempt(fn () => $this->orders->preview($order->fresh(), $adjustments)->grand_total);

        if ($total === null || ! Money::isPositive($total)) {
            return;
        }

        $account = $this->paymentAccount();
        $payment = ['account_id' => $account->id, 'amount' => $total];

        if ($account->type === 'cash') {
            $step = $this->pick([100 => 30, 500 => 40, 1000 => 30]);
            $payment['tendered'] = Money::of(ceil((float) $total / $step) * $step);
        } else {
            $payment['reference'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $account->name), 0, 3)).'-'.mt_rand(10000, 99999);
        }

        $this->attempt(fn () => $this->orders->checkout($order->fresh(), $adjustments, [$payment]));
    }

    /** @return list<array{variant_id: int, option_ids: list<int>, quantity: int}> */
    private function randomLines(int $count): array
    {
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            /** @var Product $product */
            $product = $this->products->random();
            $variant = $product->variants->random();
            $options = [];

            foreach ($product->modifierGroups as $group) {
                $available = $group->options->where('is_available', true)->values();
                $take = (int) $group->min_select;

                if ($take === 0 && mt_rand(1, 100) <= 20) {
                    $take = 1;
                }

                $take = min($take, $group->max_select ? (int) $group->max_select : $take, $available->count());

                if ($take > 0) {
                    $options = array_merge($options, $available->shuffle()->take($take)->pluck('id')->all());
                }
            }

            $lines[] = ['variant_id' => $variant->id, 'option_ids' => $options, 'quantity' => $this->pick([1 => 80, 2 => 15, 3 => 5])];
        }

        return $lines;
    }

    private function paymentAccount(): Account
    {
        $others = Account::query()->active()->where('is_default_cash', false)->get();

        if ($others->isEmpty() || mt_rand(1, 100) <= 65) {
            return $this->drawer;
        }

        return $others->random();
    }

    /** Minutes after midnight: a lunch rush, a bigger dinner rush, and a quiet afternoon. */
    private function orderMinute(): int
    {
        return match ($this->pick(['lunch' => 40, 'afternoon' => 12, 'dinner' => 48])) {
            'lunch' => mt_rand(12 * 60 + 30, 15 * 60),
            'afternoon' => mt_rand(15 * 60, 19 * 60),
            default => mt_rand(19 * 60, 22 * 60 + 30),
        };
    }

    // ---------------------------------------------------------------- money

    private function scheduleExpenses(Closure $at): void
    {
        if (mt_rand(1, 100) <= 55) {
            $this->add($at('16:30'), fn () => $this->expense('Staff meals', mt_rand(3, 7) * 100, 'Lunch for the staff'));
        }
        if ($this->riders->isNotEmpty() && mt_rand(1, 100) <= 60) {
            $this->add($at('18:00'), fn () => $this->expense('Transport & fuel', mt_rand(20, 45) * 10, 'Petrol for delivery bikes', 'Shell'));
        }
        if ($this->dayIndex % 4 === 1) {
            $this->add($at('12:00'), fn () => $this->expense('Packaging', mt_rand(12, 25) * 100, 'Boxes, bags and cups', 'Paper Pack Co.'));
        }
        if ($this->dayIndex % 7 === 2) {
            $this->add($at('11:45'), fn () => $this->expense('Cleaning & supplies', mt_rand(9, 16) * 100, 'Detergent, tissue, gloves'));
        }

        $bills = [
            3 => ['Electricity', mt_rand(180, 260) * 100, 'Monthly bill', 'LESCO', true],
            6 => ['Water', mt_rand(15, 30) * 100, 'Monthly bill', 'WASA', true],
            9 => ['Gas', mt_rand(40, 70) * 100, 'Monthly bill', 'SNGPL', true],
            11 => ['Repairs & maintenance', 2500, 'Fryer thermostat replaced', 'Ali Electric Works', false],
            12 => ['Marketing', 6000, 'Social media ads', 'Meta', true],
            13 => ['Rent', $this->shop->prefix === 'blg' ? 85000 : 60000, 'Shop rent', 'Landlord', true],
        ];

        if (isset($bills[$this->dayIndex])) {
            [$category, $amount, $note, $payee, $fromBank] = $bills[$this->dayIndex];
            $this->add($at('13:30'), fn () => $this->expense($category, $amount, $note, $payee, $fromBank));
        }
    }

    /** Pays from the bank (or wallet) when asked and it has the money, otherwise from the drawer. */
    private function expense(string $category, int $amount, string $note, ?string $payee = null, bool $fromBank = false): void
    {
        $this->as('manager');
        $account = $this->accountThatCovers($amount, $fromBank);

        if (! $account) {
            return;
        }

        $this->attempt(fn () => $this->expenses->record([
            'expense_category_id' => ExpenseCategory::query()->where('name', $category)->value('id'),
            'account_id' => $account->id,
            'amount' => $amount,
            'payee' => $payee,
            'note' => $note,
        ]));
    }

    private function accountThatCovers(int|string $amount, bool $preferBank): ?Account
    {
        $candidates = Account::query()->active()->ordered()->get();
        $order = $preferBank
            ? $candidates->sortBy(fn (Account $a) => $a->is_default_cash ? 1 : 0)
            : $candidates->sortBy(fn (Account $a) => $a->is_default_cash ? 0 : 1);

        return $order->first(fn (Account $a) => Money::compare($a->current_balance, $amount) >= 0);
    }

    /** Takes the day's cash above the float to the bank. */
    private function bankTheCash(): void
    {
        $to = Account::query()->active()->where('type', 'bank')->first()
            ?? Account::query()->active()->where('is_default_cash', false)->first();
        $spare = floor(((float) $this->drawer->fresh()->current_balance - self::FLOAT) / 1000) * 1000;

        if ($to && $spare >= 1000) {
            $this->as('manager');
            $this->attempt(fn () => $this->moves->transfer($this->drawer->id, $to->id, $spare, 'Banked, deposit slip '.mt_rand(1000, 9999)));
        }
    }

    private function sweepWallets(): void
    {
        $bank = Account::query()->active()->where('type', 'bank')->first();

        foreach (Account::query()->active()->where('type', 'wallet')->get() as $wallet) {
            $amount = floor((float) $wallet->current_balance / 100) * 100;
            if ($bank && $amount >= 1000) {
                $this->as('manager');
                $this->attempt(fn () => $this->moves->transfer($wallet->id, $bank->id, $amount, 'Weekly wallet sweep'));
            }
        }
    }

    private function ownerDrawing(): void
    {
        $amount = mt_rand(3, 6) * 1000;

        if (Money::compare($this->drawer->fresh()->current_balance, $amount + self::FLOAT) >= 0) {
            $this->as('owner');
            $this->attempt(fn () => $this->moves->adjust($this->drawer->id, 'out', $amount, 'Owner drawing'));
        }
    }

    /** Buys whatever won't last the day, enough for about two and a half days. */
    private function restock(): void
    {
        $lines = [];
        $used = StockMovement::query()
            ->where('type', 'sale')
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('ingredient_id, -SUM(quantity) as used')
            ->groupBy('ingredient_id')
            ->pluck('used', 'ingredient_id');

        foreach (Ingredient::query()->active()->get() as $ingredient) {
            $warn = max((float) $ingredient->low_stock_threshold, 1.0);
            $daily = (float) ($used[$ingredient->id] ?? 0);
            $stock = (float) $ingredient->current_stock;

            if ($stock < max($warn * 2, $daily * 1.5)) {
                $quantity = ceil(max($warn * 4, $daily * 2.5) - $stock);
                $price = (float) $ingredient->avg_cost * mt_rand(95, 110) / 100;
                $lines[] = [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $quantity,
                    'unit' => $ingredient->unit,
                    'line_total' => round($quantity * $price),
                ];
            }
        }

        if ($lines === []) {
            return;
        }

        $total = array_sum(array_column($lines, 'line_total'));
        $this->as('manager');
        $this->attempt(fn () => $this->purchases->record([
            'supplier_name' => self::SUPPLIERS[array_rand(self::SUPPLIERS)],
            'account_id' => $this->accountThatCovers($total, true)?->id,
            'note' => 'Restock',
        ], $lines));
    }

    // ---------------------------------------------------------------- helpers

    private function load(Shop $shop): void
    {
        $this->shop = $shop;
        $users = User::ofShop($shop)->with('role')->get();
        $owner = $users->first(fn (User $u) => $u->role?->name === 'Owner');

        $this->staff = [
            'owner' => $owner,
            'manager' => $users->first(fn (User $u) => $u->role?->name === 'Manager') ?? $owner,
            'cashier' => $users->first(fn (User $u) => $u->role?->name === 'Cashier') ?? $owner,
        ];

        $this->drawer = Account::query()->where('is_default_cash', true)->firstOrFail();
        $this->products = Product::query()->where('is_available', true)
            ->with(['variants' => fn ($q) => $q->where('is_available', true), 'modifierGroups.options'])
            ->get()
            ->filter(fn (Product $p) => $p->variants->isNotEmpty())
            ->values();
        $this->tables = DiningTable::query()->where('is_active', true)->get();
        $this->waiters = Waiter::query()->where('type', 'waiter')->where('is_active', true)->get();
        $this->riders = Waiter::query()->where('type', 'rider')->where('is_active', true)->get();
        $this->customers = Customer::query()->get();
    }

    private function as(string $role): void
    {
        Auth::guard('shop')->setUser($this->staff[$role]);
    }

    private function add(Carbon $at, Closure $run): void
    {
        $this->events[] = ['at' => $at->copy(), 'seq' => ++$this->seq, 'run' => $run];
    }

    /** Runs the queued events in time order, with the clock set to each one. */
    private function play(): void
    {
        $events = $this->events;
        $this->events = [];
        usort($events, fn ($a, $b) => [$a['at']->getTimestamp(), $a['seq']] <=> [$b['at']->getTimestamp(), $b['seq']]);

        foreach ($events as $event) {
            Carbon::setTestNow($event['at']->copy()->utc());
            ($event['run'])();
        }

        Carbon::setTestNow();
    }

    /** A rule the shop would also enforce (stock, balances...) just skips that step. */
    private function attempt(Closure $action): mixed
    {
        try {
            return $action();
        } catch (PosException) {
            return null;
        }
    }

    /** @param  array<int|string, int>  $weights */
    private function pick(array $weights): int|string
    {
        $roll = mt_rand(1, max(1, array_sum($weights)));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
