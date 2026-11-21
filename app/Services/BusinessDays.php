<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemVoid;
use App\Models\Payment;
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

    public function current(): ?BusinessDay
    {
        return BusinessDay::query()->open()->latest('id')->first();
    }

    public function requireOpen(): BusinessDay
    {
        return $this->current() ?? throw new PosException('The business day is not open. Open the day before taking orders.');
    }

    public function cashDrawer(): Account
    {
        return Account::query()->where('is_default_cash', true)->firstOrFail();
    }

    public function open(string|int|float $countedCash, ?string $note = null): BusinessDay
    {
        return DB::transaction(function () use ($countedCash, $note) {
            // One open day per shop: opening is serialised on the shop row.
            $shop = Shop::query()->whereKey($this->tenancy->id())->lockForUpdate()->firstOrFail();

            if (BusinessDay::query()->open()->lockForUpdate()->exists()) {
                throw new PosException('The business day is already open.');
            }

            $drawer = $this->cashDrawer();
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

            $drawer = $this->cashDrawer();
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

        $cashSales = Money::of($byAccount->firstWhere('account_id', $drawer->id)?->amount);
        $expected = $day->isOpen() ? Money::of($drawer->current_balance) : Money::of($day->expected_cash);

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
            'cash' => [
                'opening' => Money::of($day->opening_cash_counted),
                'sales' => $cashSales,
                'other' => Money::sub(Money::sub($expected, $day->opening_cash_counted), $cashSales),
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
