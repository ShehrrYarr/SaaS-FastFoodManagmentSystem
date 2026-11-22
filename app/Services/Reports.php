<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BusinessDay;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemVoid;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Waiter;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales and money figures for a range of business dates.
 *
 * Sales count on the business day an order was paid (the same rule as the day
 * close), so a month here equals the sum of that month's Z reports. Expenses
 * count on the date they were spent, purchases on the purchase date.
 */
class Reports
{
    /** Ranges longer than this are charted by month instead of by day. */
    public const DAILY_LIMIT = 62;

    public function __construct(private TenantContext $tenancy) {}

    /** @return array<string, mixed> */
    public function build(string $from, string $to): array
    {
        $shop = $this->tenancy->shop();
        $days = BusinessDay::query()->whereBetween('business_date', [$from, $to])->get(['id', 'business_date']);
        $dayIds = $days->pluck('id');
        $dateOfDay = $days->mapWithKeys(fn (BusinessDay $d) => [$d->id => $d->business_date->toDateString()]);

        $completed = Order::query()->whereIn('closed_business_day_id', $dayIds)->where('status', 'completed');

        $totals = (clone $completed)->selectRaw('
            COUNT(*) as orders,
            COALESCE(SUM(guests), 0) as guests,
            COALESCE(SUM(subtotal), 0) as subtotal,
            COALESCE(SUM(item_discount_total + discount_amount), 0) as discounts,
            COALESCE(SUM(service_charge), 0) as service_charge,
            COALESCE(SUM(delivery_charge), 0) as delivery_charge
        ')->first();

        $payments = Payment::query()->whereIn('business_day_id', $dayIds)
            ->selectRaw('business_day_id, account_id, COUNT(*) as payments, SUM(amount) as amount')
            ->groupBy('business_day_id', 'account_id')
            ->get();

        $sales = Money::sum($payments->pluck('amount')->all());
        $orders = (int) $totals->orders;

        $voids = OrderItemVoid::query()->whereIn('business_day_id', $dayIds)
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity, COALESCE(SUM(amount), 0) as amount')->first();

        $expenses = $this->expenses($from, $to);
        $purchases = Purchase::query()->whereBetween('purchased_on', [$from, $to])
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(total), 0) as amount')->first();

        $cost = $shop->inventory_tracking_enabled ? $this->ingredientCost($dayIds) : null;
        $profit = Money::sub(Money::sub($sales, $cost ?? 0), $expenses['amount']);

        return [
            'from' => $from,
            'to' => $to,
            'business_days' => $days->count(),
            'sales' => $sales,
            'orders' => $orders,
            'average' => $orders > 0 ? Money::of(bcdiv($sales, (string) $orders, 4)) : '0.00',
            'guests' => (int) $totals->guests,
            'subtotal' => Money::of($totals->subtotal),
            'discounts' => Money::of($totals->discounts),
            'service_charge' => Money::of($totals->service_charge),
            'delivery_charge' => Money::of($totals->delivery_charge),
            'cancelled' => Order::query()->whereIn('closed_business_day_id', $dayIds)->where('status', 'cancelled')->count(),
            'voids' => ['quantity' => (int) $voids->quantity, 'amount' => Money::of($voids->amount)],
            'trend' => $this->trend($from, $to, $payments, $completed, $dateOfDay),
            'by_account' => $this->byAccount($payments),
            'by_type' => $this->byType($completed),
            'by_category' => $this->byCategory($completed),
            'items' => $this->items($completed),
            'hours' => $this->hours($completed, $shop->timezone),
            'cashiers' => $this->cashiers($completed),
            'waiters' => $this->waiters($completed),
            'expenses' => $expenses,
            'purchases' => ['count' => (int) $purchases->entries, 'amount' => Money::of($purchases->amount)],
            'cost' => $cost,
            'food_cost_percent' => $cost !== null && Money::isPositive($sales) ? round((float) $cost / (float) $sales * 100, 1) : null,
            'profit' => $profit,
        ];
    }

    /**
     * The few figures the dashboard needs, without the full breakdowns.
     *
     * @return array{sales: string, orders: int, expenses: string, cost: ?string, profit: string, trend: array}
     */
    public function quick(string $from, string $to): array
    {
        $days = BusinessDay::query()->whereBetween('business_date', [$from, $to])->get(['id', 'business_date']);
        $dayIds = $days->pluck('id');
        $completed = Order::query()->whereIn('closed_business_day_id', $dayIds)->where('status', 'completed');
        $payments = Payment::query()->whereIn('business_day_id', $dayIds)
            ->selectRaw('business_day_id, SUM(amount) as amount')->groupBy('business_day_id')->get();

        $sales = Money::sum($payments->pluck('amount')->all());
        $expenses = Money::of(Expense::query()->counted()->whereBetween('spent_on', [$from, $to])->sum('amount'));
        $cost = $this->tenancy->shop()->inventory_tracking_enabled ? $this->ingredientCost($dayIds) : null;

        return [
            'sales' => $sales,
            'orders' => (clone $completed)->count(),
            'expenses' => $expenses,
            'cost' => $cost,
            'profit' => Money::sub(Money::sub($sales, $cost ?? 0), $expenses),
            'trend' => $this->trend($from, $to, $payments, $completed, $days->mapWithKeys(fn (BusinessDay $d) => [$d->id => $d->business_date->toDateString()])),
        ];
    }

    /**
     * Sales and orders per date (or per month for long ranges), with every
     * date in the range present so quiet days show as zero.
     */
    private function trend(string $from, string $to, Collection $payments, $completed, Collection $dateOfDay): array
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        $monthly = $start->diffInDays($end) + 1 > self::DAILY_LIMIT;
        $key = fn (string $date) => $monthly ? substr($date, 0, 7) : $date;

        $buckets = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $monthly ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addDay()) {
            $buckets[$key($cursor->toDateString())] = [
                'key' => $key($cursor->toDateString()),
                'label' => $monthly ? $cursor->format('M Y') : $cursor->format('D d M'),
                'short' => $monthly ? $cursor->format('M') : $cursor->format('d M'),
                'sales' => '0.00',
                'orders' => 0,
            ];
        }

        foreach ($payments as $row) {
            $k = $key($dateOfDay[$row->business_day_id]);
            $buckets[$k]['sales'] = Money::add($buckets[$k]['sales'], $row->amount);
        }

        $ordersByDay = (clone $completed)->selectRaw('closed_business_day_id, COUNT(*) as n')->groupBy('closed_business_day_id')->pluck('n', 'closed_business_day_id');
        foreach ($ordersByDay as $dayId => $n) {
            $buckets[$key($dateOfDay[$dayId])]['orders'] += (int) $n;
        }

        return ['by' => $monthly ? 'month' : 'day', 'points' => array_values($buckets)];
    }

    private function byAccount(Collection $payments): array
    {
        $names = Account::query()->whereIn('id', $payments->pluck('account_id')->unique())->pluck('name', 'id');

        return $payments->groupBy('account_id')->map(fn (Collection $rows, $accountId) => [
            'account' => $names[$accountId] ?? 'Account #'.$accountId,
            'payments' => (int) $rows->sum('payments'),
            'amount' => Money::sum($rows->pluck('amount')->all()),
        ])->sortByDesc(fn ($row) => (float) $row['amount'])->values()->all();
    }

    private function byType($completed): array
    {
        $rows = (clone $completed)->selectRaw('type, COUNT(*) as orders, SUM(grand_total) as amount')->groupBy('type')->get()->keyBy('type');

        return collect(Order::TYPES)->map(fn ($label, $type) => [
            'type' => $label,
            'orders' => (int) ($rows[$type]->orders ?? 0),
            'amount' => Money::of($rows[$type]->amount ?? 0),
        ])->values()->all();
    }

    private function byCategory($completed): array
    {
        return OrderItem::query()
            ->whereIn('order_items.order_id', (clone $completed)->select('id'))
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('categories.name as category, SUM(order_items.quantity - order_items.voided_quantity) as quantity, SUM(order_items.line_total) as amount')
            ->groupBy('categories.name')
            ->havingRaw('SUM(order_items.quantity - order_items.voided_quantity) > 0')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category ?? 'No category',
                'quantity' => (int) $row->quantity,
                'amount' => Money::of($row->amount),
            ])->all();
    }

    private function items($completed): array
    {
        return OrderItem::query()
            ->whereIn('order_id', (clone $completed)->select('id'))
            ->selectRaw('name, variant_name, SUM(quantity - voided_quantity) as quantity, SUM(line_total) as amount')
            ->groupBy('name', 'variant_name')
            ->havingRaw('SUM(quantity - voided_quantity) > 0')
            ->orderByDesc('amount')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->variant_name ? $row->name.' ('.$row->variant_name.')' : $row->name,
                'quantity' => (int) $row->quantity,
                'amount' => Money::of($row->amount),
            ])->all();
    }

    /** Orders and sales by hour of the day they were paid, in the shop's time zone. */
    private function hours($completed, string $timezone): array
    {
        $hours = [];

        foreach ((clone $completed)->toBase()->select(['closed_at', 'grand_total'])->cursor() as $row) {
            $hour = Carbon::parse($row->closed_at, 'UTC')->timezone($timezone)->hour;
            $hours[$hour] ??= ['orders' => 0, 'sales' => '0.00'];
            $hours[$hour]['orders']++;
            $hours[$hour]['sales'] = Money::add($hours[$hour]['sales'], $row->grand_total);
        }

        if ($hours === []) {
            return [];
        }

        // From the first to the last busy hour, so the chart shows trading hours only.
        return collect(range(min(array_keys($hours)), max(array_keys($hours))))->map(fn ($h) => [
            'hour' => $h,
            'label' => Carbon::createFromTime($h)->format('g A'),
            'orders' => $hours[$h]['orders'] ?? 0,
            'sales' => $hours[$h]['sales'] ?? '0.00',
        ])->all();
    }

    private function cashiers($completed): array
    {
        $rows = (clone $completed)->selectRaw('closed_by, COUNT(*) as orders, SUM(grand_total) as amount')->groupBy('closed_by')->get();
        $names = User::query()->whereIn('id', $rows->pluck('closed_by')->filter())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name' => $names[$row->closed_by] ?? '—',
            'orders' => (int) $row->orders,
            'amount' => Money::of($row->amount),
        ])->sortByDesc(fn ($row) => (float) $row['amount'])->values()->all();
    }

    private function waiters($completed): array
    {
        $rows = (clone $completed)->whereNotNull('waiter_id')
            ->selectRaw('waiter_id, COUNT(*) as orders, SUM(guests) as guests, SUM(grand_total) as amount')
            ->groupBy('waiter_id')->get();
        $names = Waiter::query()->whereIn('id', $rows->pluck('waiter_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name' => $names[$row->waiter_id] ?? '—',
            'orders' => (int) $row->orders,
            'guests' => (int) $row->guests,
            'amount' => Money::of($row->amount),
        ])->sortByDesc(fn ($row) => (float) $row['amount'])->values()->all();
    }

    private function expenses(string $from, string $to): array
    {
        $rows = Expense::query()->counted()->whereBetween('spent_on', [$from, $to])
            ->selectRaw('expense_category_id, COUNT(*) as entries, SUM(amount) as amount')
            ->groupBy('expense_category_id')->get();
        $names = ExpenseCategory::query()->whereIn('id', $rows->pluck('expense_category_id'))->pluck('name', 'id');

        return [
            'count' => (int) $rows->sum('entries'),
            'amount' => Money::sum($rows->pluck('amount')->all()),
            'by_category' => $rows->map(fn ($row) => [
                'category' => $names[$row->expense_category_id] ?? '—',
                'count' => (int) $row->entries,
                'amount' => Money::of($row->amount),
            ])->sortByDesc(fn ($row) => (float) $row['amount'])->values()->all(),
        ];
    }

    /**
     * What the ingredients sent to the kitchen cost, at the average cost when they
     * were taken (sales less what voids and cancellations gave back).
     */
    private function ingredientCost(Collection $dayIds): string
    {
        $cost = StockMovement::query()
            ->whereIn('business_day_id', $dayIds)
            ->whereIn('type', ['sale', 'void_return'])
            ->selectRaw('COALESCE(SUM(-quantity * COALESCE(unit_cost, 0)), 0) as cost')
            ->value('cost');

        return Money::of($cost);
    }
}
