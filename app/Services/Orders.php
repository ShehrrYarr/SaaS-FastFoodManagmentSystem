<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\BusinessDay;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Kot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemVoid;
use App\Models\Payment;
use App\Models\ShopSequence;
use App\Models\Waiter;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes an order: sending items to the kitchen, voids,
 * discounts, delivery dispatch, cancelling and checkout. Each change runs in
 * one transaction with the order row locked, so two tills working on the same
 * tab can't overwrite each other.
 */
class Orders
{
    public function __construct(
        private TenantContext $tenancy,
        private BusinessDays $days,
        private OrderLines $lines,
        private AccountLedger $ledger,
    ) {}

    // ---------------------------------------------------------------- send to kitchen

    /**
     * Sends new lines to the kitchen as one KOT. Creates the order first when
     * $order is null (that's when it gets its number and token).
     *
     * @param  array<string, mixed>  $details  type, dining_table_id, waiter_id, guests, customer_name,
     *                                         customer_phone, customer_address, delivery_charge, notes
     * @param  list<array<string, mixed>>  $lines  variant_id, options (list of {id}) or option_ids, quantity,
     *                                             note, discount_type, discount_value
     */
    public function send(?Order $order, array $details, array $lines): Kot
    {
        if ($lines === []) {
            throw new PosException('Add some items first.');
        }

        return DB::transaction(function () use ($order, $details, $lines) {
            $day = $this->days->requireOpen();
            $order = $order ? $this->lockOpen($order) : $this->create($details, $day);

            $kot = $this->newKot($order, $day, 'new');

            foreach ($lines as $line) {
                $optionIds = $line['option_ids'] ?? array_column($line['options'] ?? [], 'id');
                $priced = $this->lines->build((int) $line['variant_id'], $optionIds, (int) $line['quantity'], $line['note'] ?? null);

                $item = new OrderItem([
                    'order_id' => $order->id,
                    'kot_id' => $kot->id,
                    'product_id' => $priced['product_id'],
                    'product_variant_id' => $priced['variant_id'],
                    'name' => $priced['name'],
                    'variant_name' => $priced['variant_name'],
                    'unit_price' => $priced['unit_price'],
                    'modifiers_total' => $priced['modifiers_total'],
                    'quantity' => $priced['quantity'],
                    'note' => $priced['note'],
                    'combo_snapshot' => $priced['combo'],
                ]);
                $this->applyDiscount($item, $line['discount_type'] ?? null, $line['discount_value'] ?? 0);
                $item->recalculate();
                $item->save();

                foreach ($priced['options'] as $option) {
                    $item->modifiers()->create([
                        'modifier_option_id' => $option['id'],
                        'group_name' => $option['group'],
                        'name' => $option['name'],
                        'price' => $option['price'],
                    ]);
                }
            }

            // M4: when inventory tracking is on, deduct recipe stock for these lines here
            // (ingredient rows locked in id order), inside this same transaction.

            $this->saveTotals($order);

            return $kot->setRelation('order', $order);
        });
    }

    /** Creates the order header: number, daily token, table/customer checks. */
    private function create(array $details, BusinessDay $day): Order
    {
        $shop = $this->tenancy->shop();
        $type = $details['type'] ?? null;

        if (! array_key_exists((string) $type, Order::TYPES)) {
            throw new PosException('Choose dine-in, takeaway or delivery.');
        }

        $attributes = $this->resolveDetails($type, $details, null);

        $sequence = ShopSequence::next($shop->id, 'order');

        return Order::create($attributes + [
            'order_number' => ShopSequence::format($shop->order_prefix, $sequence),
            'sequence' => $sequence,
            'token_number' => ShopSequence::next($shop->id, 'token', 'day-'.$day->id),
            'business_day_id' => $day->id,
            'type' => $type,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'delivery_status' => $type === 'delivery' ? 'pending' : null,
            'service_charge_type' => $type === 'dine_in' ? $shop->service_charge_type : null,
            'service_charge_value' => $type === 'dine_in' ? $shop->service_charge_value : 0,
            'opened_by' => Auth::guard('shop')->id(),
            'opened_at' => now(),
        ]);
    }

    /** Changes table, waiter, guests, customer, delivery charge or notes on an open order. */
    public function updateDetails(Order $order, array $details): Order
    {
        return DB::transaction(function () use ($order, $details) {
            $this->days->requireOpen();
            $order = $this->lockOpen($order);

            $order->fill($this->resolveDetails($order->type, $details, $order));
            $this->saveTotals($order);

            return $order;
        });
    }

    /**
     * Validates the header fields for an order type and returns the columns to store.
     *
     * @return array<string, mixed>
     */
    private function resolveDetails(string $type, array $details, ?Order $existing): array
    {
        $shop = $this->tenancy->shop();
        $tableId = $type === 'dine_in' ? ((int) ($details['dining_table_id'] ?? 0) ?: null) : null;
        $waiterId = $type === 'dine_in' ? ((int) ($details['waiter_id'] ?? 0) ?: null) : null;
        $phone = Customer::normalizePhone((string) ($details['customer_phone'] ?? ''));
        $name = $this->clean($details['customer_name'] ?? null, 120);
        $address = $this->clean($details['customer_address'] ?? null, 500);

        if ($type === 'dine_in' && ! $tableId && ! $waiterId) {
            throw new PosException('Choose a table or a waiter for this dine-in order.');
        }

        if ($tableId) {
            $table = DiningTable::query()->whereKey($tableId)->where('is_active', true)->lockForUpdate()->first()
                ?? throw new PosException('That table is not available.');

            $taken = Order::query()->open()->where('dining_table_id', $table->id)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->lockForUpdate()
                ->first();

            if ($taken) {
                throw new PosException($table->name.' already has an open tab ('.$taken->order_number.').');
            }
        }

        if ($waiterId && ! Waiter::query()->waiters()->where('is_active', true)->whereKey($waiterId)->exists()) {
            throw new PosException('That waiter is not available.');
        }

        if ($type === 'delivery') {
            if (strlen($phone) < 7) {
                throw new PosException('Enter the customer\'s phone number for delivery.');
            }
            if (! $address) {
                throw new PosException('Enter the delivery address.');
            }
        }

        $customer = strlen($phone) >= 7 ? $this->rememberCustomer($phone, $name, $address) : null;

        $guests = (int) ($details['guests'] ?? 0);
        $delivery = $type === 'delivery'
            ? Money::of(($details['delivery_charge'] ?? '') === '' ? ($existing?->delivery_charge ?? $shop->default_delivery_charge) : $details['delivery_charge'])
            : '0.00';

        if (Money::isNegative($delivery)) {
            throw new PosException('The delivery charge cannot be negative.');
        }

        return [
            'dining_table_id' => $tableId,
            'waiter_id' => $waiterId,
            'guests' => $type === 'dine_in' && $guests > 0 ? min($guests, 999) : null,
            'customer_id' => $customer?->id,
            'customer_name' => $name ?? $customer?->name,
            'customer_phone' => $phone !== '' ? $phone : null,
            'customer_address' => $type === 'delivery' ? $address : null,
            'delivery_charge' => $delivery,
            'notes' => $this->clean($details['notes'] ?? null, 500),
        ];
    }

    /** Finds the customer by phone (creating them if new) and keeps their latest name and address. */
    private function rememberCustomer(string $phone, ?string $name, ?string $address): Customer
    {
        $customer = Customer::query()->where('phone', $phone)->first() ?? new Customer(['phone' => $phone, 'name' => $name ?? 'Customer']);

        if ($name) {
            $customer->name = $name;
        }
        if ($address) {
            $customer->address = $address;
        }

        $customer->save();

        return $customer;
    }

    // ---------------------------------------------------------------- changes to sent lines

    /** Sets or clears the discount on one line ("percent" or "fixed"). */
    public function discountItem(OrderItem $item, ?string $type, string|int|float|null $value): Order
    {
        return DB::transaction(function () use ($item, $type, $value) {
            $this->days->requireOpen();
            $order = $this->lockOpen($item->order);
            $item = $order->items->firstWhere('id', $item->id) ?? throw new PosException('That line is not on this order.');

            $this->applyDiscount($item, $type, $value);
            $item->recalculate();
            $item->save();

            $this->saveTotals($order);

            return $order;
        });
    }

    /**
     * Takes back some of a sent line. The reason is required and kept with the
     * user and time; a VOID KOT tells the kitchen.
     */
    public function voidItem(OrderItem $item, int $quantity, string $reason): Kot
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new PosException('Give a reason for the void.');
        }

        return DB::transaction(function () use ($item, $quantity, $reason) {
            $day = $this->days->requireOpen();
            $order = $this->lockOpen($item->order);
            $item = $order->items->firstWhere('id', $item->id) ?? throw new PosException('That line is not on this order.');

            if ($quantity < 1 || $quantity > $item->activeQuantity()) {
                throw new PosException('You can void between 1 and '.$item->activeQuantity().' of '.$item->displayName().'.');
            }

            $kot = $this->newKot($order, $day, 'void');
            $this->recordVoid($order, $item, $quantity, $reason, $kot, $day);

            // M4: return the stock for the voided quantity here.

            $this->saveTotals($order);

            return $kot->setRelation('order', $order);
        });
    }

    /** Cancels an unpaid order: voids whatever is left (one VOID KOT) and frees the table. */
    public function cancel(Order $order, string $reason): ?Kot
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new PosException('Give a reason for cancelling.');
        }

        return DB::transaction(function () use ($order, $reason) {
            $day = $this->days->requireOpen();
            $order = $this->lockOpen($order);

            $active = $order->items->filter(fn (OrderItem $item) => $item->activeQuantity() > 0);
            $kot = $active->isNotEmpty() ? $this->newKot($order, $day, 'void') : null;

            foreach ($active as $item) {
                $this->recordVoid($order, $item, $item->activeQuantity(), 'Order cancelled: '.$reason, $kot, $day);
            }

            $order->fill([
                'status' => 'cancelled',
                'delivery_status' => null,
                'cancel_reason' => mb_substr($reason, 0, 255),
                'closed_by' => Auth::guard('shop')->id(),
                'closed_at' => now(),
                'closed_business_day_id' => $day->id,
            ]);
            $this->saveTotals($order);

            return $kot?->setRelation('order', $order);
        });
    }

    /** Hands a delivery order to a rider. */
    public function dispatchRider(Order $order, int $riderId): Order
    {
        return DB::transaction(function () use ($order, $riderId) {
            $order = $this->lockOpen($order);

            if (! $order->isDelivery()) {
                throw new PosException('Only delivery orders go out with a rider.');
            }

            if (! Waiter::query()->riders()->where('is_active', true)->whereKey($riderId)->exists()) {
                throw new PosException('Choose an active rider.');
            }

            $order->update([
                'rider_id' => $riderId,
                'delivery_status' => 'out',
                'dispatched_at' => $order->dispatched_at ?? now(),
            ]);

            return $order;
        });
    }

    // ---------------------------------------------------------------- checkout

    /**
     * Applies final discounts/charges and takes payment, split across accounts.
     * The payments must add up to the bill exactly; cash lines may be handed
     * more (tendered) and the difference is change.
     *
     * @param  array{discount_type?: ?string, discount_value?: mixed, waive_service_charge?: bool, delivery_charge?: mixed}  $adjustments
     * @param  list<array{account_id: int, amount: mixed, tendered?: mixed, reference?: ?string}>  $payments
     */
    public function checkout(Order $order, array $adjustments, array $payments): Order
    {
        return DB::transaction(function () use ($order, $adjustments, $payments) {
            $day = $this->days->requireOpen();
            $order = $this->lockOpen($order);

            if ($order->items->every(fn (OrderItem $item) => $item->activeQuantity() === 0)) {
                throw new PosException('There is nothing to charge on this order. Cancel it instead.');
            }

            $this->applyAdjustments($order, $adjustments);
            $order->recalculate();

            $lines = $this->validatePayments($order, $payments);

            $paid = '0.00';
            $change = '0.00';

            foreach ($lines as $line) {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'account_id' => $line['account']->id,
                    'business_day_id' => $day->id,
                    'amount' => $line['amount'],
                    'tendered' => $line['tendered'],
                    'change_given' => $line['change'],
                    'reference' => $line['reference'],
                    'received_by' => Auth::guard('shop')->id(),
                ]);

                $this->ledger->post($line['account'], 'sale', $line['amount'], $payment, 'Sale '.$order->order_number, businessDayId: $day->id);

                $paid = Money::add($paid, $line['amount']);
                $change = Money::add($change, $line['change']);
            }

            $order->fill([
                'status' => 'completed',
                'payment_status' => 'paid',
                'delivery_status' => $order->isDelivery() ? 'delivered' : null,
                'paid_total' => $paid,
                'change_total' => $change,
                'closed_by' => Auth::guard('shop')->id(),
                'closed_at' => now(),
                'closed_business_day_id' => $day->id,
            ]);
            $this->saveTotals($order);

            if ($order->customer_id) {
                $customer = Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
                $customer?->forceFill([
                    'orders_count' => $customer->orders_count + 1,
                    'total_spent' => Money::add($customer->total_spent, $order->grand_total),
                    'last_order_at' => now(),
                ])->save();
            }

            return $order->load('payments.account');
        });
    }

    /** Same maths as checkout, without saving: for the checkout screen's live totals. */
    public function preview(Order $order, array $adjustments): Order
    {
        $preview = clone $order;
        $preview->setRelation('items', $order->items->map(fn (OrderItem $item) => clone $item));
        $this->applyAdjustments($preview, $adjustments);
        $preview->recalculate();

        return $preview;
    }

    private function applyAdjustments(Order $order, array $adjustments): void
    {
        if (array_key_exists('discount_type', $adjustments)) {
            $type = $adjustments['discount_type'] ?: null;
            $value = Money::of($adjustments['discount_value'] ?? 0);

            if ($type && ! in_array($type, ['percent', 'fixed'], true)) {
                throw new PosException('Unknown discount type.');
            }
            if (Money::isNegative($value) || ($type === 'percent' && Money::compare($value, 100) > 0)) {
                throw new PosException('Enter a discount between 0 and 100%.');
            }

            $order->discount_type = $type && Money::isPositive($value) ? $type : null;
            $order->discount_value = $order->discount_type ? $value : '0.00';
        }

        if (! empty($adjustments['waive_service_charge'])) {
            $order->service_charge_value = '0.00';
        }

        if ($order->isDelivery() && array_key_exists('delivery_charge', $adjustments) && $adjustments['delivery_charge'] !== '' && $adjustments['delivery_charge'] !== null) {
            $charge = Money::of($adjustments['delivery_charge']);

            if (Money::isNegative($charge)) {
                throw new PosException('The delivery charge cannot be negative.');
            }

            $order->delivery_charge = $charge;
        }
    }

    /**
     * @return list<array{account: Account, amount: string, tendered: ?string, change: string, reference: ?string}>
     */
    private function validatePayments(Order $order, array $payments): array
    {
        $lines = [];
        $total = '0.00';

        foreach ($payments as $payment) {
            $amount = Money::of($payment['amount'] ?? 0);

            if (Money::isZero($amount) && blank($payment['tendered'] ?? null)) {
                continue; // an empty row on the screen
            }

            if (! Money::isPositive($amount)) {
                throw new PosException('Each payment must be more than zero.');
            }

            $account = Account::query()->active()->find((int) ($payment['account_id'] ?? 0))
                ?? throw new PosException('Choose an account for each payment.');

            $tendered = null;
            $change = '0.00';

            if ($account->type === 'cash' && filled($payment['tendered'] ?? null)) {
                $tendered = Money::of($payment['tendered']);

                if (Money::compare($tendered, $amount) < 0) {
                    throw new PosException('Cash received is less than the amount for '.$account->name.'.');
                }

                $change = Money::sub($tendered, $amount);
            }

            $lines[] = [
                'account' => $account,
                'amount' => $amount,
                'tendered' => $tendered,
                'change' => $change,
                'reference' => $this->clean($payment['reference'] ?? null, 80),
            ];
            $total = Money::add($total, $amount);
        }

        if (Money::compare($total, $order->grand_total) !== 0) {
            throw new PosException(Money::compare($total, $order->grand_total) < 0
                ? 'Payments are '.Money::sub($order->grand_total, $total).' short of the bill.'
                : 'Payments are '.Money::sub($total, $order->grand_total).' more than the bill. Enter extra cash as "received" to give change.');
        }

        return $lines;
    }

    // ---------------------------------------------------------------- helpers

    /** Re-reads the order with a row lock and makes sure it is still open. */
    private function lockOpen(Order $order): Order
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first()
            ?? throw new PosException('That order no longer exists.');

        if (! $locked->isOpen()) {
            throw new PosException($locked->order_number.' is already '.($locked->status === 'completed' ? 'paid' : 'cancelled').'.');
        }

        return $this->withLockedItems($locked);
    }

    /**
     * Loads the order's lines with a locking read. A plain read inside a transaction can
     * return MySQL's older snapshot and miss lines another till added while we waited.
     */
    private function withLockedItems(Order $order): Order
    {
        return $order->setRelation('items', OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get());
    }

    private function newKot(Order $order, BusinessDay $day, string $type): Kot
    {
        return Kot::create([
            'order_id' => $order->id,
            'business_day_id' => $day->id,
            'number' => ShopSequence::next($order->tenant_id, 'kot', 'day-'.$day->id),
            'type' => $type,
            'created_by' => Auth::guard('shop')->id(),
        ]);
    }

    private function recordVoid(Order $order, OrderItem $item, int $quantity, string $reason, ?Kot $kot, BusinessDay $day): void
    {
        OrderItemVoid::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'kot_id' => $kot?->id,
            'business_day_id' => $day->id,
            'quantity' => $quantity,
            'amount' => Money::mul($item->eachPrice(), $quantity),
            'reason' => mb_substr($reason, 0, 255),
            'user_id' => Auth::guard('shop')->id(),
        ]);

        $item->voided_quantity += $quantity;
        $item->recalculate();
        $item->save();
    }

    private function applyDiscount(OrderItem $item, ?string $type, string|int|float|null $value): void
    {
        $type = $type ?: null;
        $value = Money::of($value);

        if ($type && ! in_array($type, ['percent', 'fixed'], true)) {
            throw new PosException('Unknown discount type.');
        }
        if (Money::isNegative($value) || ($type === 'percent' && Money::compare($value, 100) > 0)) {
            throw new PosException('Enter a discount between 0 and 100%.');
        }

        $item->discount_type = $type && Money::isPositive($value) ? $type : null;
        $item->discount_value = $item->discount_type ? $value : '0.00';
    }

    private function saveTotals(Order $order): void
    {
        $this->withLockedItems($order);
        $order->recalculate();
        $order->save();
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
