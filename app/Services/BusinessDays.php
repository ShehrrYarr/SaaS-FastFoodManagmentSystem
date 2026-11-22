<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BusinessDay;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemVoid;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Shop;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Opening and closing the trading day.
 *
 * Open: count the drawer; any difference from the Cash Drawer's ledger
 * balance is posted as cash over/short, so the ledger matches the drawer.
 * Close: count again; expected cash is the drawer's ledger balance; the
 * difference is posted and a summary of the day is stored with it.
 */
class BusinessDays
{
    public function __construct(private TenantContext $tenancy, private AccountLedger $ledger) {}

    /**
     * The open day, if any. Inside a transaction that posts money, pass $lock: it takes a
     * shared lock on the day row, so closing the day (which locks it exclusively) waits for
     * in-flight sales and expenses, and they wait for the close. Every drawer posting then
     * lands either fully inside the day's figures or after it.
     */
    public function current(bool $lock = false): ?BusinessDay
    {
        return BusinessDay::query()->open()->latest('id')->when($lock, fn ($q) => $q->sharedLock())->first();
    }

    /** The open day, locked as in current(). Use inside a transaction. */
    public function requireOpen(): BusinessDay
    {
        return $this->current(lock: true) ?? throw new PosException('The business day is not open. Open the day before taking orders.');
    }

    public function cashDrawer(bool $lock = false): Account
    {
        return Account::query()->where('is_default_cash', true)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function open(string|int|float $countedCash, ?string $note = null): BusinessDay
    {
        return DB::transaction(function () use ($countedCash, $note) {
            // One open day per shop: opening is serialised on the shop row.
            $shop = Shop::query()->whereKey($this->tenancy->id())->lockForUpdate()->firstOrFail();

            if (BusinessDay::query()->open()->lockForUpdate()->exists()) {
                throw new PosException('The business day is already open.');
            }

            $drawer = $this->cashDrawer(lock: true);
            $expected = Money::of($drawer->current_balance);
            $counted = Money::of($countedCash);

            if (Money::isNegative($counted)) {
                throw new PosException('The cash count cannot be negative.');
            }

            $day = BusinessDay::create([
                'business_date' => $shop->today()->toDateString(),
                'status' => 'open',
                'opened_by' => Auth::guard('shop')->id(),
                'opened_at' => now(),
                'opening_cash_expected' => $expected,
                'opening_cash_counted' => $counted,
                'opening_note' => $this->clean($note),
            ]);

            $difference = Money::sub($counted, $expected);

            if (! Money::isZero($difference)) {
                $this->ledger->post($drawer, 'over_short', $difference, $day, 'Opening cash count', businessDayId: $day->id);
            }

            return $day;
        });
    }

    public function close(BusinessDay $day, string|int|float $countedCash, ?string $note = null): BusinessDay
    {
        return DB::transaction(function () use ($day, $countedCash, $note) {
            $day = BusinessDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();

            if (! $day->isOpen()) {
                throw new PosException('This business day is already closed.');
            }

            $counted = Money::of($countedCash);

            if (Money::isNegative($counted)) {
                throw new PosException('The cash count cannot be negative.');
            }

            // Locked: no posting can change the drawer between reading it and closing.
            $drawer = $this->cashDrawer(lock: true);
            $expected = Money::of($drawer->current_balance);
            $difference = Money::sub($counted, $expected);

            if (! Money::isZero($difference)) {
                $this->ledger->post($drawer, 'over_short', $difference, $day, 'Closing cash count', businessDayId: $day->id);
            }

            $day->fill([
                'status' => 'closed',
                'closed_by' => Auth::guard('shop')->id(),
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'cash_difference' => $difference,
                'closing_note' => $this->clean($note),
            ]);

            $day->summary = $this->summary($day);
            $day->save();

            return $day;
        });
    }

    /**
     * Figures for one day. Orders count on the day they were paid or cancelled;
     * tabs still open at closing carry over to the next day.
     *
     * @return array<string, mixed>
     */
    public function summary(BusinessDay $day): array
    {
        $drawer = $this->cashDrawer();

        $byAccount = Payment::query()
            ->where('business_day_id', $day->id)
            ->selectRaw('account_id, COUNT(*) as payments, SUM(amount) as amount')
            ->groupBy('account_id')
            ->get();

        $accountNames = Account::query()->whereIn('id', $byAccount->pluck('account_id'))->pluck('name', 'id');

        $completed = Order::query()->where('closed_business_day_id', $day->id)->where('status', 'completed');

        $totals = (clone $completed)->selectRaw('
            COUNT(*) as orders,
            COALESCE(SUM(subtotal), 0) as subtotal,
            COALESCE(SUM(item_discount_total + discount_amount), 0) as discounts,
            COALESCE(SUM(service_charge), 0) as service_charge,
            COALESCE(SUM(delivery_charge), 0) as delivery_charge,
            COALESCE(SUM(grand_total), 0) as grand_total
        ')->first();

        $byType = (clone $completed)
            ->selectRaw('type, COUNT(*) as orders, SUM(grand_total) as amount')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $items = OrderItem::query()
            ->whereIn('order_id', (clone $completed)->select('id'))
            ->selectRaw('name, variant_name, SUM(quantity - voided_quantity) as quantity, SUM(line_total) as amount')
            ->groupBy('name', 'variant_name')
            ->havingRaw('SUM(quantity - voided_quantity) > 0')
            ->orderByDesc('quantity')
            ->limit(50)
            ->get();

        $voids = OrderItemVoid::query()->where('business_day_id', $day->id)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(quantity), 0) as quantity, COALESCE(SUM(amount), 0) as amount')
            ->first();

        $open = Order::query()->open()->selectRaw('COUNT(*) as count, COALESCE(SUM(grand_total), 0) as amount')->first();

        // The drawer's own ledger for the day. Over/short entries are the cash counts themselves,
        // so opening count + these entries must equal the drawer balance (expected cash).
        $drawerRows = AccountTransaction::query()
            ->where('account_id', $drawer->id)
            ->where('business_day_id', $day->id)
            ->where('type', '!=', 'over_short')
            ->selectRaw('type, COUNT(*) as entries, SUM(amount) as amount')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $cashSales = Money::of($drawerRows['sale']->amount ?? 0);
        $movements = collect(AccountTransaction::TYPES)
            ->filter(fn ($label, $type) => $type !== 'sale' && isset($drawerRows[$type]))
            ->map(fn ($label, $type) => [
                'label' => $label,
                'entries' => (int) $drawerRows[$type]->entries,
                'amount' => Money::of($drawerRows[$type]->amount),
            ])
            ->values()
            ->all();
        $fromLedger = Money::sum([$day->opening_cash_counted, ...$drawerRows->pluck('amount')->all()]);
        $expected = $day->isOpen() ? Money::of($drawer->current_balance) : Money::of($day->expected_cash);

        $expenses = Expense::query()->counted()->where('business_day_id', $day->id)
            ->selectRaw('expense_category_id, COUNT(*) as entries, SUM(amount) as amount')
            ->groupBy('expense_category_id')
            ->get();
        $categoryNames = ExpenseCategory::query()->whereIn('id', $expenses->pluck('expense_category_id'))->pluck('name', 'id');

        $purchases = Purchase::query()->where('business_day_id', $day->id)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(total), 0) as amount')
            ->first();

        return [
            'sales' => Money::of($byAccount->sum('amount')),
            'orders' => (int) $totals->orders,
            'average' => (int) $totals->orders > 0 ? Money::of((float) $totals->grand_total / (int) $totals->orders) : '0.00',
            'subtotal' => Money::of($totals->subtotal),
            'discounts' => Money::of($totals->discounts),
            'service_charge' => Money::of($totals->service_charge),
            'delivery_charge' => Money::of($totals->delivery_charge),
            'cancelled' => Order::query()->where('closed_business_day_id', $day->id)->where('status', 'cancelled')->count(),
            'by_account' => $byAccount->map(fn ($row) => [
                'account' => $accountNames[$row->account_id] ?? 'Account #'.$row->account_id,
                'payments' => (int) $row->payments,
                'amount' => Money::of($row->amount),
            ])->sortByDesc('amount')->values()->all(),
            'by_type' => collect(Order::TYPES)->map(fn ($label, $type) => [
                'type' => $label,
                'orders' => (int) ($byType[$type]->orders ?? 0),
                'amount' => Money::of($byType[$type]->amount ?? 0),
            ])->values()->all(),
            'items' => $items->map(fn ($row) => [
                'name' => $row->variant_name ? $row->name.' ('.$row->variant_name.')' : $row->name,
                'quantity' => (int) $row->quantity,
                'amount' => Money::of($row->amount),
            ])->all(),
            'voids' => [
                'count' => (int) $voids->count,
                'quantity' => (int) $voids->quantity,
                'amount' => Money::of($voids->amount),
            ],
            'open_tabs' => [
                'count' => (int) $open->count,
                'amount' => Money::of($open->amount),
            ],
            'expenses' => [
                'count' => (int) $expenses->sum('entries'),
                'amount' => Money::sum($expenses->pluck('amount')->all()),
                'by_category' => $expenses->map(fn ($row) => [
                    'category' => $categoryNames[$row->expense_category_id] ?? 'Category #'.$row->expense_category_id,
                    'count' => (int) $row->entries,
                    'amount' => Money::of($row->amount),
                ])->sortByDesc('amount')->values()->all(),
            ],
            'purchases' => [
                'count' => (int) $purchases->entries,
                'amount' => Money::of($purchases->amount),
            ],
            'cash' => [
                'opening' => Money::of($day->opening_cash_counted),
                'sales' => $cashSales,
                // Expenses, purchases, transfers and money in/out paid through the drawer.
                'movements' => $movements,
                // Anything in the balance the ledger can't explain (should always be zero).
                'other' => Money::sub($expected, $fromLedger),
                'expected' => $expected,
                'counted' => $day->isOpen() ? null : Money::of($day->counted_cash),
                'difference' => $day->isOpen() ? null : Money::of($day->cash_difference),
            ],
        ];
    }

    private function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 255);
    }
}
