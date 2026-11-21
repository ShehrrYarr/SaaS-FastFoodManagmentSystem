<?php

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Kot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Waiter;
use App\Services\BusinessDays;
use App\Services\OrderLines;
use App\Services\Orders;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Point of sale. New lines live in this component (and the session, so they
 * survive moving between pages) until "Send to kitchen" saves them on the
 * order and prints a KOT. The order itself is created on its first send.
 */
new #[Layout('layouts::shop')] class extends Component
{
    #[Locked]
    public ?int $orderId = null;

    // New order details (an existing order's details are edited through the same fields).
    public string $type = 'dine_in';
    public string $tableId = '';
    public string $waiterId = '';
    public string $guests = '';
    public string $customerPhone = '';
    public string $customerName = '';
    public string $customerAddress = '';
    public string $deliveryCharge = '';
    public string $orderNote = '';
    public bool $showDetails = false;

    /** Unsent lines, priced on the server. Locked: the browser can't change prices or quantities directly. */
    #[Locked]
    public array $lines = [];

    // Line editor: an unsent line (note + discount) or a sent item (discount only).
    public bool $showLine = false;
    #[Locked]
    public ?string $lineKey = null;
    #[Locked]
    public ?int $lineItemId = null;
    public string $lineNote = '';
    public string $lineDiscountType = '';
    public string $lineDiscountValue = '';

    public bool $showVoid = false;
    #[Locked]
    public ?int $voidItemId = null;
    public string $voidQty = '1';
    public string $voidReason = '';

    public bool $showCancel = false;
    public string $cancelReason = '';

    public bool $showDispatch = false;
    public string $riderId = '';

    public bool $showCheckout = false;
    public string $discountType = '';
    public string $discountValue = '';
    public bool $waiveService = false;
    public string $checkoutDelivery = '';
    public array $payments = [];

    public const VOID_REASONS = ['Customer changed mind', 'Wrong item entered', 'Out of stock', 'Kitchen mistake', 'Took too long'];

    public function mount(?Order $order = null): void
    {
        if ($order?->exists) {
            if (! $order->isOpen()) {
                $this->redirectRoute('shop.orders.show', ['order' => $order->id], navigate: true);

                return;
            }

            $this->orderId = $order->id;
            $this->type = $order->type;
        }

        $this->restoreDraft();

        if (! $this->orderId) {
            $this->applyQuery();
        } elseif (request()->boolean('pay') && $this->lines === []) {
            $this->openCheckout();
        }
    }

    public function render()
    {
        $title = $this->order ? $this->order->order_number : 'New order';

        return $this->view()->title('POS · '.$title);
    }

    /** Keeps the unsent cart in the session after every request. */
    public function dehydrate(): void
    {
        $this->saveDraft();
    }

    // ---------------------------------------------------------------- data

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function day()
    {
        return app(BusinessDays::class)->current();
    }

    #[Computed]
    public function order(): ?Order
    {
        return $this->orderId
            ? Order::query()->with(['items.modifiers', 'table', 'waiter', 'rider', 'kots'])->find($this->orderId)
            : null;
    }

    #[Computed]
    public function tables()
    {
        return DiningTable::query()->where('is_active', true)->ordered()->get();
    }

    /** dining_table_id => open order (number, id) */
    #[Computed]
    public function occupied()
    {
        return Order::query()->open()->whereNotNull('dining_table_id')->get(['id', 'order_number', 'dining_table_id'])->keyBy('dining_table_id');
    }

    #[Computed]
    public function waiters()
    {
        return Waiter::query()->waiters()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function riders()
    {
        return Waiter::query()->riders()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function accounts()
    {
        return Account::query()->active()->ordered()->get();
    }

    #[Computed]
    public function knownCustomer(): ?Customer
    {
        $phone = Customer::normalizePhone($this->customerPhone);

        return strlen($phone) >= 7 ? Customer::query()->where('phone', $phone)->first() : null;
    }

    /** The bill as it would be with the unsent lines added. */
    #[Computed]
    public function cart(): Order
    {
        $shop = $this->shop;

        if ($this->order) {
            $preview = clone $this->order;
            $items = $this->order->items->map(fn (OrderItem $item) => clone $item);
        } else {
            $preview = new Order([
                'type' => $this->type,
                'service_charge_type' => $this->type === 'dine_in' ? $shop->service_charge_type : null,
                'service_charge_value' => $this->type === 'dine_in' ? $shop->service_charge_value : 0,
                'delivery_charge' => $this->type === 'delivery'
                    ? Money::of($this->deliveryCharge === '' ? $shop->default_delivery_charge : $this->deliveryCharge)
                    : 0,
            ]);
            $items = collect();
        }

        foreach ($this->lines as $line) {
            $items->push(new OrderItem([
                'unit_price' => $line['unit_price'],
                'modifiers_total' => $line['modifiers_total'],
                'quantity' => $line['quantity'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
            ]));
        }

        $preview->setRelation('items', $items);
        $preview->recalculate();

        return $preview;
    }

    #[Computed]
    public function checkoutPreview(): ?Order
    {
        if (! $this->order) {
            return null;
        }

        try {
            return app(Orders::class)->preview($this->order, $this->adjustments());
        } catch (PosException) {
            return app(Orders::class)->preview($this->order, []);
        }
    }

    public function lineCount(): int
    {
        return (int) array_sum(array_column($this->lines, 'quantity'));
    }

    public function canDiscount(): bool
    {
        return Gate::allows('orders.discount');
    }

    // ---------------------------------------------------------------- cart (unsent lines)

    /** Called by the menu grid (sfms-pos.js). Prices are looked up here, never taken from the browser. */
    public function addLine(int $variantId, array $optionIds = [], int $quantity = 1, string $note = ''): void
    {
        if (! $this->day) {
            $this->toast('error', 'Open the business day first.');

            return;
        }

        try {
            $line = app(OrderLines::class)->build($variantId, $optionIds, $quantity, $note);
        } catch (PosException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        foreach ($this->lines as $i => $existing) {
            if ($existing['signature'] === $line['signature'] && ! $existing['discount_type']) {
                $this->lines[$i]['quantity'] = min(OrderLines::MAX_QUANTITY, $existing['quantity'] + $line['quantity']);

                return;
            }
        }

        $this->lines[] = $line;
    }

    public function increment(string $key): void
    {
        $this->changeQuantity($key, 1);
    }

    public function decrement(string $key): void
    {
        $this->changeQuantity($key, -1);
    }

    public function removeLine(string $key): void
    {
        $this->lines = array_values(array_filter($this->lines, fn ($line) => $line['key'] !== $key));
    }

    public function clearLines(): void
    {
        $this->lines = [];
    }

    private function changeQuantity(string $key, int $delta): void
    {
        foreach ($this->lines as $i => $line) {
            if ($line['key'] === $key) {
                $quantity = $line['quantity'] + $delta;

                if ($quantity < 1) {
                    $this->removeLine($key);
                } else {
                    $this->lines[$i]['quantity'] = min(OrderLines::MAX_QUANTITY, $quantity);
                }

                return;
            }
        }
    }

    public function editLine(string $key): void
    {
        $line = collect($this->lines)->firstWhere('key', $key);

        if (! $line) {
            return;
        }

        $this->lineKey = $key;
        $this->lineItemId = null;
        $this->lineNote = (string) $line['note'];
        $this->lineDiscountType = (string) $line['discount_type'];
        $this->lineDiscountValue = $line['discount_type'] ? $this->plain($line['discount_value']) : '';
        $this->resetValidation();
        $this->showLine = true;
    }

    public function editItem(int $itemId): void
    {
        if (! $this->canDiscount() || ! $this->order?->items->firstWhere('id', $itemId)) {
            return;
        }

        $item = $this->order->items->firstWhere('id', $itemId);

        $this->lineKey = null;
        $this->lineItemId = $item->id;
        $this->lineNote = (string) $item->note;
        $this->lineDiscountType = (string) $item->discount_type;
        $this->lineDiscountValue = $item->discount_type ? $this->plain($item->discount_value) : '';
        $this->resetValidation();
        $this->showLine = true;
    }

    public function saveLine(): void
    {
        $this->validate([
            'lineNote' => ['nullable', 'string', 'max:255'],
            'lineDiscountType' => ['nullable', 'in:percent,fixed'],
            'lineDiscountValue' => ['nullable', 'numeric', 'min:0', $this->lineDiscountType === 'percent' ? 'max:100' : 'max:9999999'],
        ], [], ['lineDiscountValue' => 'discount']);

        $type = $this->lineDiscountType !== '' && (float) $this->lineDiscountValue > 0 ? $this->lineDiscountType : null;

        if ($type && ! $this->canDiscount()) {
            $this->toast('error', 'You are not allowed to give discounts.');

            return;
        }

        if ($this->lineItemId) {
            $item = $this->order?->items->firstWhere('id', $this->lineItemId);

            try {
                $item && app(Orders::class)->discountItem($item, $type, $type ? $this->lineDiscountValue : 0);
            } catch (PosException $e) {
                $this->toast('error', $e->getMessage());

                return;
            }

            $this->refreshOrder();
        } else {
            foreach ($this->lines as $i => $line) {
                if ($line['key'] === $this->lineKey) {
                    $note = trim($this->lineNote);
                    $this->lines[$i]['note'] = $note === '' ? null : $note;
                    $this->lines[$i]['discount_type'] = $type;
                    $this->lines[$i]['discount_value'] = $type ? Money::of($this->lineDiscountValue) : '0.00';
                    $this->lines[$i]['signature'] = app(OrderLines::class)->signature($this->lines[$i]);
                }
            }
        }

        $this->showLine = false;
    }

    // ---------------------------------------------------------------- order details

    public function setType(string $type): void
    {
        if ($this->orderId || ! array_key_exists($type, Order::TYPES)) {
            return;
        }

        $this->type = $type;

        if ($type !== 'dine_in') {
            $this->tableId = '';
            $this->waiterId = '';
            $this->guests = '';
        }
    }

    public function openDetails(): void
    {
        if ($order = $this->order) {
            $this->tableId = (string) $order->dining_table_id;
            $this->waiterId = (string) $order->waiter_id;
            $this->guests = (string) $order->guests;
            $this->customerPhone = (string) $order->customer_phone;
            $this->customerName = (string) $order->customer_name;
            $this->customerAddress = (string) $order->customer_address;
            $this->deliveryCharge = $this->plain($order->delivery_charge);
            $this->orderNote = (string) $order->notes;
        }

        $this->resetValidation();
        $this->showDetails = true;
    }

    public function updatedCustomerPhone(): void
    {
        $customer = $this->knownCustomer;

        if ($customer) {
            if (trim($this->customerName) === '') {
                $this->customerName = $customer->name;
            }
            if (trim($this->customerAddress) === '' && $customer->address) {
                $this->customerAddress = $customer->address;
            }
        }
    }

    public function saveDetails(): void
    {
        $this->validate([
            'guests' => ['nullable', 'integer', 'min:0', 'max:999'],
            'customerPhone' => ['nullable', 'string', 'max:30'],
            'customerName' => ['nullable', 'string', 'max:120'],
            'customerAddress' => ['nullable', 'string', 'max:500'],
            'deliveryCharge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'orderNote' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->order) {
            try {
                app(Orders::class)->updateDetails($this->order, $this->details());
            } catch (PosException $e) {
                $this->toast('error', $e->getMessage());

                return;
            }

            $this->refreshOrder();
            $this->toast('success', 'Order details saved.');
        }

        $this->showDetails = false;
    }

    /** @return array<string, mixed> */
    private function details(): array
    {
        return [
            'type' => $this->order?->type ?? $this->type,
            'dining_table_id' => $this->tableId,
            'waiter_id' => $this->waiterId,
            'guests' => $this->guests,
            'customer_phone' => $this->customerPhone,
            'customer_name' => $this->customerName,
            'customer_address' => $this->customerAddress,
            'delivery_charge' => $this->deliveryCharge,
            'notes' => $this->orderNote,
        ];
    }

    /** Opens the details sheet when a new order is missing what its type needs. */
    private function detailsMissing(): bool
    {
        if ($this->orderId) {
            return false;
        }

        $message = match (true) {
            $this->type === 'dine_in' && $this->tableId === '' && $this->waiterId === '' => 'Choose a table or a waiter first.',
            $this->type === 'delivery' && (strlen(Customer::normalizePhone($this->customerPhone)) < 7 || trim($this->customerAddress) === '') => 'Enter the customer\'s phone and address first.',
            default => null,
        };

        if ($message) {
            $this->toast('warning', $message);
            $this->openDetails();
        }

        return $message !== null;
    }

    // ---------------------------------------------------------------- kitchen

    public function send(): void
    {
        $order = $this->sendLines();

        if (! $order) {
            return;
        }

        if ($order->wasRecentlyCreated) {
            $this->toast('success', $order->order_number.' sent to the kitchen · Token '.$order->token_number);
            $this->dispatch('pos-close-cart');
            $this->startNewOrder();
        } else {
            $this->toast('success', 'Sent to the kitchen.');
        }
    }

    /** Send new lines (if any), then take payment. */
    public function charge(): void
    {
        if (! Gate::allows('checkout.take_payment')) {
            $this->toast('error', 'You are not allowed to take payments.');

            return;
        }

        if ($this->lines !== [] && ! $this->sendLines()) {
            return;
        }

        if (! $this->orderId) {
            $this->toast('warning', 'Add some items first.');

            return;
        }

        $this->openCheckout();
    }

    /** Saves the cart as a KOT. Returns the order, or null if something stopped it. */
    private function sendLines(): ?Order
    {
        if ($this->lines === []) {
            $this->toast('warning', 'Add some items first.');

            return null;
        }

        if ($this->detailsMissing()) {
            return null;
        }

        try {
            $kot = app(Orders::class)->send($this->order, $this->orderId ? [] : $this->details(), $this->lines);
        } catch (PosException $e) {
            $this->toast('error', $e->getMessage());

            return null;
        }

        $this->printKot($kot);
        $this->forgetDraft();
        $this->lines = [];

        $order = $kot->order;
        $order->wasRecentlyCreated = ! $this->orderId;

        $isNew = ! $this->orderId;
        $this->orderId = $order->id;
        $this->refreshOrder();

        if ($isNew) {
            $this->replaceUrl(route('shop.pos.order', ['order' => $order->id]));
        }

        return $order;
    }

    public function printBill(): void
    {
        if ($this->order) {
            $this->dispatch('sfms-print', url: route('shop.print.receipt', ['order' => $this->order->id]), kind: 'receipt');
        }
    }

    public function reprintKot(int $kotId): void
    {
        $kot = $this->order?->kots->firstWhere('id', $kotId);

        if ($kot) {
            $this->dispatch('sfms-print', url: route('shop.print.kot', ['kot' => $kot->id]), kind: 'kot');
        }
    }

    // ---------------------------------------------------------------- voids & cancel

    public function openVoid(int $itemId): void
    {
        if (! Gate::allows('orders.void')) {
            $this->toast('error', 'You are not allowed to void items.');

            return;
        }

        $item = $this->order?->items->firstWhere('id', $itemId);

        if (! $item || $item->activeQuantity() === 0) {
            return;
        }

        $this->voidItemId = $item->id;
        $this->voidQty = (string) $item->activeQuantity();
        $this->voidReason = '';
        $this->resetValidation();
        $this->showVoid = true;
    }

    public function confirmVoid(): void
    {
        $item = $this->order?->items->firstWhere('id', $this->voidItemId);

        if (! $item || ! Gate::allows('orders.void')) {
            $this->showVoid = false;

            return;
        }

        $this->validate([
            'voidQty' => ['required', 'integer', 'min:1', 'max:'.$item->activeQuantity()],
            'voidReason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['voidQty' => 'quantity', 'voidReason' => 'reason']);

        try {
            $kot = app(Orders::class)->voidItem($item, (int) $this->voidQty, $this->voidReason);
        } catch (PosException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->printKot($kot);
        $this->showVoid = false;
        $this->refreshOrder();
        $this->toast('success', 'Voided '.$this->voidQty.' × '.$item->displayName().'.');
    }

    public function openCancel(): void
    {
        if (! $this->order || ! Gate::allows('orders.void')) {
            $this->toast('error', 'You are not allowed to cancel orders.');

            return;
        }

        $this->cancelReason = '';
        $this->resetValidation();
        $this->showCancel = true;
    }

    public function confirmCancel(): void
    {
        if (! $this->order || ! Gate::allows('orders.void')) {
            $this->showCancel = false;

            return;
        }

        $this->validate(['cancelReason' => ['required', 'string', 'min:3', 'max:255']], [], ['cancelReason' => 'reason']);

        $number = $this->order->order_number;

        try {
            $kot = app(Orders::class)->cancel($this->order, $this->cancelReason);
        } catch (PosException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        if ($kot) {
            $this->printKot($kot);
        }

        $this->showCancel = false;
        $this->toast('success', $number.' cancelled.');
        $this->startNewOrder();
    }

    // ---------------------------------------------------------------- delivery

    public function openDispatch(): void
    {
        if (! $this->order?->isDelivery()) {
            return;
        }

        $this->riderId = (string) ($this->order->rider_id ?? '');
        $this->resetValidation();
        $this->showDispatch = true;
    }

    public function confirmDispatch(): void
    {
        $this->validate(['riderId' => ['required', 'integer']], [], ['riderId' => 'rider']);

        try {
            app(Orders::class)->dispatchRider($this->order, (int) $this->riderId);
        } catch (PosException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->showDispatch = false;
        $this->refreshOrder();
        $this->printBill();
        $this->toast('success', $this->order->order_number.' is out for delivery with '.$this->order->rider?->name.'.');
    }

    // ---------------------------------------------------------------- checkout

    public function openCheckout(): void
    {
        $order = $this->order;

        if (! $order || ! Gate::allows('checkout.take_payment')) {
            return;
        }

        if ($this->lines !== []) {
            $this->toast('warning', 'Send the new items to the kitchen first.');

            return;
        }

        $this->discountType = (string) $order->discount_type;
        $this->discountValue = $order->discount_type ? $this->plain($order->discount_value) : '';
        $this->waiveService = false;
        $this->checkoutDelivery = $this->plain($order->delivery_charge);
        unset($this->checkoutPreview);

        $cash = $this->accounts->firstWhere('is_default_cash', true) ?? $this->accounts->first();
        $this->payments = [$this->paymentRow($cash?->id, $this->checkoutPreview->grand_total)];

        $this->resetValidation();
        $this->showCheckout = true;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['discountType', 'discountValue', 'waiveService', 'checkoutDelivery'], true)) {
            unset($this->checkoutPreview);

            // One payment line follows the bill; split payments are left as entered.
            if (count($this->payments) === 1 && $this->checkoutPreview) {
                $this->payments[0]['amount'] = $this->plain($this->checkoutPreview->grand_total);
            }
        }
    }

    public function addPayment(): void
    {
        $remaining = Money::sub($this->checkoutPreview?->grand_total ?? 0, $this->paymentsTotal());
        $used = array_column($this->payments, 'account_id');
        $next = $this->accounts->first(fn ($a) => ! in_array((string) $a->id, $used, true)) ?? $this->accounts->first();

        $this->payments[] = $this->paymentRow($next?->id, Money::isPositive($remaining) ? $remaining : 0);
    }

    public function removePayment(int $index): void
    {
        if (count($this->payments) > 1) {
            unset($this->payments[$index]);
            $this->payments = array_values($this->payments);
        }
    }

    /** Puts whatever is still unpaid on this line. */
    public function fillRemaining(int $index): void
    {
        if (! isset($this->payments[$index])) {
            return;
        }

        $others = Money::sub($this->paymentsTotal(), $this->payments[$index]['amount'] ?: 0);
        $remaining = Money::sub($this->checkoutPreview?->grand_total ?? 0, $others);
        $this->payments[$index]['amount'] = $this->plain(Money::max($remaining, 0));
    }

    public function setTendered(int $index, string $amount): void
    {
        if (isset($this->payments[$index])) {
            $this->payments[$index]['tendered'] = $amount;
        }
    }

    public function paymentsTotal(): string
    {
        return Money::sum(array_map(fn ($p) => is_numeric($p['amount'] ?? null) ? $p['amount'] : 0, $this->payments));
    }

    public function completeCheckout(): void
    {
        if (! $this->order || ! Gate::allows('checkout.take_payment')) {
            return;
        }

        $this->validate([
            'payments' => ['array'],
            'payments.*.account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
        ], [], [
            'payments.*.account_id' => 'account',
            'payments.*.amount' => 'amount',
            'payments.*.tendered' => 'cash received',
        ]);

        try {
            $order = app(Orders::class)->checkout($this->order, $this->adjustments(), $this->payments);
        } catch (PosException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->showCheckout = false;
        $this->dispatch('sfms-print', url: route('shop.print.receipt', ['order' => $order->id]), kind: 'receipt');
        $this->dispatch('pos-paid',
            title: $order->order_number.' paid',
            subtitle: $this->shop->money($order->grand_total).' · Token '.$order->token_number,
            change: Money::isPositive($order->change_total) ? $this->shop->money($order->change_total) : null,
        );

        $this->startNewOrder();
    }

    /** @return array<string, mixed> */
    private function adjustments(): array
    {
        $adjustments = ['delivery_charge' => $this->checkoutDelivery];

        if ($this->canDiscount()) {
            $adjustments['discount_type'] = $this->discountType;
            $adjustments['discount_value'] = is_numeric($this->discountValue) ? $this->discountValue : 0;
            $adjustments['waive_service_charge'] = $this->waiveService;
        }

        return $adjustments;
    }

    private function paymentRow(?int $accountId, string|float|int $amount): array
    {
        return ['account_id' => (string) $accountId, 'amount' => $this->plain($amount), 'tendered' => '', 'reference' => ''];
    }

    // ---------------------------------------------------------------- helpers

    public function startNewOrder(): void
    {
        $this->forgetDraft();
        $wasExisting = $this->orderId !== null;

        $this->reset([
            'orderId', 'lines', 'tableId', 'waiterId', 'guests', 'customerPhone', 'customerName',
            'customerAddress', 'deliveryCharge', 'orderNote', 'payments', 'discountType', 'discountValue',
            'waiveService', 'checkoutDelivery', 'showCheckout', 'showDetails', 'showLine', 'showVoid',
            'showCancel', 'showDispatch',
        ]);

        if (! in_array($this->type, array_keys(Order::TYPES), true)) {
            $this->type = 'dine_in';
        }

        $this->refreshOrder();

        if ($wasExisting) {
            $this->replaceUrl(route('shop.pos'));
        }
    }

    private function refreshOrder(): void
    {
        unset($this->order, $this->cart, $this->checkoutPreview, $this->occupied);
    }

    private function printKot(Kot $kot): void
    {
        $this->dispatch('sfms-print', url: route('shop.print.kot', ['kot' => $kot->id]), kind: 'kot');
    }

    /** Points the address bar (and tab title) at the order now on screen, without a reload. */
    private function replaceUrl(string $url): void
    {
        $title = 'POS · '.($this->order?->order_number ?? 'New order').' · '.$this->shop->name;

        $this->js('history.replaceState(history.state, "", '.json_encode($url).'); document.title = '.json_encode($title));
    }

    private function toast(string $type, string $message): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    public function plain(string|float|int|null $amount): string
    {
        $amount = Money::of($amount);

        return str_ends_with($amount, '.00') ? substr($amount, 0, -3) : $amount;
    }

    /** ?type=takeaway or ?table=5 from the Open Tabs board. */
    private function applyQuery(): void
    {
        $type = (string) request()->query('type', '');

        if (array_key_exists($type, Order::TYPES)) {
            $this->setType($type);
        }

        $tableId = (int) request()->query('table', 0);

        if ($tableId && ($table = DiningTable::query()->where('is_active', true)->find($tableId))) {
            if ($open = Order::query()->open()->where('dining_table_id', $table->id)->first()) {
                $this->redirectRoute('shop.pos.order', ['order' => $open->id], navigate: true);

                return;
            }

            $this->type = 'dine_in';
            $this->tableId = (string) $table->id;
        }
    }

    private function draftKey(): string
    {
        return 'pos-draft.'.$this->shop->id.'.'.($this->orderId ?? 'new');
    }

    private function saveDraft(): void
    {
        if (! $this->shop) {
            return;
        }

        $draft = ['lines' => $this->lines];

        if (! $this->orderId) {
            $draft += [
                'type' => $this->type, 'tableId' => $this->tableId, 'waiterId' => $this->waiterId, 'guests' => $this->guests,
                'customerPhone' => $this->customerPhone, 'customerName' => $this->customerName,
                'customerAddress' => $this->customerAddress, 'deliveryCharge' => $this->deliveryCharge, 'orderNote' => $this->orderNote,
            ];
        }

        $empty = $this->lines === [] && ($this->orderId || ($this->tableId === '' && $this->waiterId === '' && $this->customerPhone === '' && $this->customerName === ''));

        $empty ? session()->forget($this->draftKey()) : session()->put($this->draftKey(), $draft);
    }

    private function restoreDraft(): void
    {
        $draft = session($this->draftKey());

        if (! is_array($draft)) {
            return;
        }

        $this->lines = array_values(array_filter($draft['lines'] ?? [], fn ($line) => is_array($line) && isset($line['key'], $line['signature'])));

        if (! $this->orderId) {
            foreach (['type', 'tableId', 'waiterId', 'guests', 'customerPhone', 'customerName', 'customerAddress', 'deliveryCharge', 'orderNote'] as $field) {
                if (isset($draft[$field]) && is_string($draft[$field])) {
                    $this->{$field} = $draft[$field];
                }
            }
        }
    }

    private function forgetDraft(): void
    {
        session()->forget($this->draftKey());
    }
};
?>

@php
    $shop = $this->shop;
    $order = $this->order;
    $cart = $this->cart;
    $orderType = $order?->type ?? $type;
    $money = fn ($v) => $shop->money($v);
    $newCount = $this->lineCount();
    $canVoid = auth('shop')->user()->can('orders.void');
    $canPay = auth('shop')->user()->can('checkout.take_payment');
    $canDiscount = $this->canDiscount();
@endphp

<div class="sfms-pos">
    @if (! $this->day)
        <x-page-header title="Point of sale" />
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card mb-0">
                    <x-empty-state icon="ri-store-3-line" title="The business day isn't open">
                        @can('day.open_close')
                            Count the cash drawer and open the day to start taking orders.
                            <div class="mt-3">
                                <a href="{{ route('shop.day') }}" wire:navigate class="btn btn-success btn-touch"><i class="ri-sun-line"></i>Open the day</a>
                            </div>
                        @else
                            Ask a manager to open the day before taking orders.
                        @endcan
                    </x-empty-state>
                </div>
            </div>
        </div>
    @else
        <div class="row g-3 sfms-pos-row">
            {{-- Menu --}}
            <div class="col-lg-7 col-xl-8">
                <livewire:pos.menu />
            </div>

            {{-- Order / cart: a column on large screens, a bottom sheet on phones and tablets --}}
            <div class="col-lg-5 col-xl-4">
                <div class="offcanvas-lg offcanvas-bottom sfms-pos-cart" id="posCart" tabindex="-1" aria-labelledby="posCartTitle" wire:ignore.self>
                    <div class="card mb-0 sfms-pos-cart-card">
                        {{-- Header --}}
                        <div class="card-header sfms-pos-cart-head">
                            <div class="hstack gap-2 justify-content-between">
                                @if ($order)
                                    <div class="min-w-0">
                                        <div class="hstack gap-2">
                                            <h5 class="card-title mb-0 text-truncate" id="posCartTitle">{{ $order->order_number }}</h5>
                                            <span class="badge bg-primary fs-12">Token {{ $order->token_number }}</span>
                                        </div>
                                        <div class="fs-12 text-muted">
                                            <i class="{{ \App\Models\Order::typeIcon($order->type) }}"></i> {{ $order->typeLabel() }}
                                            &middot; {{ $order->durationLabel() }}
                                            @if ($order->deliveryStatusLabel()) &middot; {{ $order->deliveryStatusLabel() }}@endif
                                        </div>
                                    </div>
                                @else
                                    <h5 class="card-title mb-0" id="posCartTitle">New order</h5>
                                @endif

                                <div class="hstack gap-1 flex-shrink-0">
                                    <div class="dropdown" wire:ignore x-data="{ kot: sfmsPrint.enabled('kot'), receipt: sfmsPrint.enabled('receipt') }">
                                        <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Printing on this device" title="Printing on this device">
                                            <i class="ri-printer-line"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 15rem">
                                            <div class="fw-semibold mb-2">Print on this device</div>
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" id="print-kot" x-model="kot" x-on:change="sfmsPrint.set('kot', kot)">
                                                <label class="form-check-label" for="print-kot">Kitchen tickets</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="print-receipt" x-model="receipt" x-on:change="sfmsPrint.set('receipt', receipt)">
                                                <label class="form-check-label" for="print-receipt">Receipts &amp; bills</label>
                                            </div>
                                            <div class="fs-12 text-muted mt-2">Turn these off on phones or tills without a printer.</div>
                                        </div>
                                    </div>
                                    @if ($order)
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="More actions"><i class="ri-more-2-fill"></i></button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <button type="button" class="dropdown-item" wire:click="openDetails"><i class="ri-edit-line me-2"></i>Order details</button>
                                                <button type="button" class="dropdown-item" wire:click="printBill"><i class="ri-bill-line me-2"></i>Print bill</button>
                                                @if ($order->kots->isNotEmpty())
                                                    <button type="button" class="dropdown-item" wire:click="reprintKot({{ $order->kots->last()->id }})"><i class="ri-printer-line me-2"></i>Reprint last KOT</button>
                                                @endif
                                                @if ($order->isDelivery())
                                                    <button type="button" class="dropdown-item" wire:click="openDispatch"><i class="ri-e-bike-2-line me-2"></i>{{ $order->rider_id ? 'Change rider' : 'Send out with rider' }}</button>
                                                @endif
                                                <a class="dropdown-item" href="{{ route('shop.orders.show', ['order' => $order->id]) }}" wire:navigate><i class="ri-file-list-3-line me-2"></i>Order history</a>
                                                @if ($canVoid)
                                                    <div class="dropdown-divider"></div>
                                                    <button type="button" class="dropdown-item text-danger" wire:click="openCancel"><i class="ri-close-circle-line me-2"></i>Cancel order</button>
                                                @endif
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-light" wire:click="startNewOrder" title="New order"><i class="ri-add-line"></i><span class="d-none d-sm-inline ms-1">New</span></button>
                                    @endif
                                    <button type="button" class="btn-close d-lg-none ms-1" data-bs-dismiss="offcanvas" data-bs-target="#posCart" aria-label="Close"></button>
                                </div>
                            </div>

                            @unless ($order)
                                <div class="sfms-type-switch mt-3" role="group" aria-label="Order type">
                                    @foreach (\App\Models\Order::TYPES as $value => $label)
                                        <button type="button" class="btn btn-sm {{ $type === $value ? 'btn-primary' : 'btn-light' }}" wire:click="setType('{{ $value }}')">
                                            <i class="{{ \App\Models\Order::typeIcon($value) }}"></i>{{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                            @endunless

                            {{-- Who / where --}}
                            @php
                                $table = $order?->table ?? ($tableId ? $this->tables->firstWhere('id', (int) $tableId) : null);
                                $waiter = $order?->waiter ?? ($waiterId ? $this->waiters->firstWhere('id', (int) $waiterId) : null);
                                $chips = match ($orderType) {
                                    'dine_in' => array_filter([$table?->name, $waiter?->name, ($order?->guests ?? (int) $guests) ? ($order?->guests ?? (int) $guests).' guests' : null]),
                                    'delivery' => array_filter([$order?->customer_name ?? $customerName, $order?->customer_phone ?? $customerPhone, $order?->rider?->name ? 'Rider: '.$order->rider->name : null]),
                                    default => array_filter([$order?->customer_name ?? $customerName]),
                                };
                                $placeholder = match ($orderType) {
                                    'dine_in' => 'Choose table & waiter',
                                    'delivery' => 'Add customer & address',
                                    default => 'Add customer name (optional)',
                                };
                            @endphp
                            <button type="button" class="sfms-details-chip mt-2" wire:click="openDetails">
                                <i class="{{ $chips ? 'ri-user-location-line' : 'ri-add-circle-line' }}"></i>
                                <span class="text-truncate {{ $chips ? '' : 'text-muted' }}">{{ $chips ? implode(' · ', $chips) : $placeholder }}</span>
                                <i class="ri-arrow-right-s-line ms-auto text-muted"></i>
                            </button>
                        </div>

                        {{-- Lines --}}
                        <div class="sfms-pos-lines">
                            @if ($order && $order->items->isNotEmpty())
                                <div class="sfms-pos-section">Sent to kitchen</div>
                                @foreach ($order->items as $item)
                                    @php($active = $item->activeQuantity())
                                    <div class="sfms-pos-line {{ $active === 0 ? 'is-voided' : '' }}" wire:key="item-{{ $item->id }}">
                                        <div class="sfms-pos-line-qty">{{ $active }}&times;</div>
                                        <div class="min-w-0 flex-grow-1">
                                            <div class="fw-medium text-truncate">{{ $item->displayName() }}</div>
                                            @if ($item->modifiers->isNotEmpty())
                                                <div class="fs-12 text-muted">+ {{ $item->modifiers->pluck('name')->implode(', ') }}</div>
                                            @endif
                                            @if ($item->note)<div class="fs-12 text-warning-emphasis">“{{ $item->note }}”</div>@endif
                                            @if ($item->voided_quantity)<div class="fs-12 text-danger">{{ $item->voided_quantity }} voided</div>@endif
                                            @if ((float) $item->discount_amount > 0)<div class="fs-12 text-success">Discount −{{ $money($item->discount_amount) }}</div>@endif
                                        </div>
                                        <div class="text-end flex-shrink-0">
                                            <div class="fw-semibold">{{ $money($item->line_total) }}</div>
                                            @if ($active > 0 && ($canVoid || $canDiscount))
                                                <div class="dropdown">
                                                    <button type="button" class="btn btn-link btn-sm p-0 text-muted fs-12" data-bs-toggle="dropdown">Change</button>
                                                    <div class="dropdown-menu dropdown-menu-end">
                                                        @if ($canDiscount)
                                                            <button type="button" class="dropdown-item" wire:click="editItem({{ $item->id }})"><i class="ri-percent-line me-2"></i>Discount</button>
                                                        @endif
                                                        @if ($canVoid)
                                                            <button type="button" class="dropdown-item text-danger" wire:click="openVoid({{ $item->id }})"><i class="ri-arrow-go-back-line me-2"></i>Void</button>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if ($lines !== [])
                                <div class="sfms-pos-section hstack justify-content-between">
                                    <span>{{ $order ? 'New items' : 'Items' }} <span class="badge bg-warning-subtle text-warning ms-1">Not sent</span></span>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-danger" wire:click="clearLines" wire:confirm="Remove all unsent items?">Clear</button>
                                </div>
                                @foreach ($lines as $line)
                                    <div class="sfms-pos-line is-new" wire:key="line-{{ $line['key'] }}">
                                        <button type="button" class="min-w-0 flex-grow-1 text-start btn btn-link p-0 text-reset text-decoration-none" wire:click="editLine('{{ $line['key'] }}')">
                                            <div class="fw-medium text-truncate">{{ $line['variant_name'] ? $line['name'].' ('.$line['variant_name'].')' : $line['name'] }}</div>
                                            @if ($line['options'])<div class="fs-12 text-muted">+ {{ \App\Services\OrderLines::optionsLabel($line) }}</div>@endif
                                            @if ($line['note'])<div class="fs-12 text-warning-emphasis">“{{ $line['note'] }}”</div>@endif
                                            @if ($line['discount_type'])<div class="fs-12 text-success">Discount {{ $line['discount_type'] === 'percent' ? $this->plain($line['discount_value']).'%' : $money($line['discount_value']) }}</div>@endif
                                            <div class="fs-12 text-muted">{{ $money(\App\Services\OrderLines::lineTotal($line)) }}</div>
                                        </button>
                                        <div class="sfms-qty sfms-qty-sm">
                                            <button type="button" class="btn btn-light" wire:click="decrement('{{ $line['key'] }}')" aria-label="Less"><i class="{{ $line['quantity'] === 1 ? 'ri-delete-bin-line text-danger' : 'ri-subtract-line' }}"></i></button>
                                            <span class="sfms-qty-value">{{ $line['quantity'] }}</span>
                                            <button type="button" class="btn btn-light" wire:click="increment('{{ $line['key'] }}')" aria-label="More"><i class="ri-add-line"></i></button>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if ($lines === [] && (! $order || $order->items->isEmpty()))
                                <div class="text-center text-muted py-5 px-3">
                                    <i class="ri-shopping-basket-2-line fs-1 d-block mb-2 opacity-50"></i>
                                    Tap items on the menu to add them.
                                </div>
                            @endif
                        </div>

                        {{-- Totals + actions --}}
                        <div class="card-footer sfms-pos-cart-foot">
                            <div class="vstack gap-1 fs-13 mb-2">
                                <div class="hstack justify-content-between"><span class="text-muted">Subtotal</span><span>{{ $money($cart->subtotal) }}</span></div>
                                @if ((float) $cart->totalDiscount() > 0)
                                    <div class="hstack justify-content-between text-success"><span>Discounts</span><span>−{{ $money($cart->totalDiscount()) }}</span></div>
                                @endif
                                @if ((float) $cart->service_charge > 0)
                                    <div class="hstack justify-content-between"><span class="text-muted">Service charge</span><span>{{ $money($cart->service_charge) }}</span></div>
                                @endif
                                @if ($orderType === 'delivery' && (float) $cart->delivery_charge > 0)
                                    <div class="hstack justify-content-between"><span class="text-muted">Delivery</span><span>{{ $money($cart->delivery_charge) }}</span></div>
                                @endif
                                <div class="hstack justify-content-between fs-5 fw-bold mt-1"><span>Total</span><span>{{ $money($cart->grand_total) }}</span></div>
                            </div>

                            <div class="hstack gap-2">
                                <button type="button" class="btn btn-outline-primary btn-touch flex-grow-1" wire:click="send" wire:loading.attr="disabled" wire:target="send,charge" @disabled($lines === [])>
                                    <i class="ri-restaurant-line"></i>Send{{ $newCount ? ' ('.$newCount.')' : '' }}
                                </button>
                                @if ($canPay)
                                    <button type="button" class="btn btn-success btn-touch flex-grow-1" wire:click="charge" wire:loading.attr="disabled" wire:target="send,charge" @disabled($lines === [] && ! $order)>
                                        <i class="ri-bank-card-line"></i>{{ $lines !== [] ? 'Send & pay' : 'Pay' }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Phone/tablet bar that opens the order sheet --}}
        <div class="sfms-pos-bar d-lg-none">
            <button type="button" class="btn btn-primary btn-touch w-100 hstack justify-content-between" data-bs-toggle="offcanvas" data-bs-target="#posCart">
                <span class="hstack gap-2 min-w-0">
                    <span class="badge bg-white text-primary">{{ $newCount + ($order?->itemCount() ?? 0) }}</span>
                    <span class="text-truncate">{{ $order ? $order->order_number : 'View order' }}</span>
                </span>
                <span class="fw-bold">{{ $money($cart->grand_total) }} <i class="ri-arrow-up-s-line"></i></span>
            </button>
        </div>

        {{-- Order details --}}
        <x-modal wire:model="showDetails" :title="$order ? 'Order details' : \App\Models\Order::TYPES[$orderType].' details'" size="lg">
            <form wire:submit="saveDetails" novalidate>
                <div class="modal-body vstack gap-4">
                    @if ($orderType === 'dine_in')
                        <div>
                            <div class="fw-semibold mb-2">Table</div>
                            @if ($this->tables->isEmpty())
                                <p class="text-muted fs-13 mb-0">No tables set up yet. Choose a waiter instead, or add tables under Setup.</p>
                            @else
                                <div class="sfms-table-grid">
                                    @foreach ($this->tables as $t)
                                        @php($busy = $this->occupied->get($t->id))
                                        @php($mine = $order && $order->dining_table_id === $t->id)
                                        <button type="button" wire:key="dt-{{ $t->id }}"
                                                class="sfms-table-btn {{ $busy && ! $mine ? 'is-busy' : '' }}"
                                                x-bind:class="{ 'is-active': $wire.tableId == '{{ $t->id }}' }"
                                                @if ($busy && ! $mine) disabled title="Open tab {{ $busy->order_number }}" @endif
                                                x-on:click="$wire.tableId = ($wire.tableId == '{{ $t->id }}' ? '' : '{{ $t->id }}')">
                                            <span class="fw-semibold">{{ $t->name }}</span>
                                            <span class="fs-11">{{ $busy && ! $mine ? 'Busy' : ($t->capacity ? $t->capacity.' seats' : ($t->area ?: '')) }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="fw-semibold mb-2">Waiter</div>
                            @if ($this->waiters->isEmpty())
                                <p class="text-muted fs-13 mb-0">No waiters added yet.</p>
                            @else
                                <div class="sfms-filter-tabs flex-wrap">
                                    @foreach ($this->waiters as $w)
                                        <button type="button" class="btn btn-sm" wire:key="w-{{ $w->id }}"
                                                x-bind:class="$wire.waiterId == '{{ $w->id }}' ? 'btn-primary' : 'btn-light'"
                                                x-on:click="$wire.waiterId = ($wire.waiterId == '{{ $w->id }}' ? '' : '{{ $w->id }}')">{{ $w->name }}</button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <x-input model="guests" label="Guests" type="number" inputmode="numeric" min="0" max="999" class="col-6 col-sm-4" />
                    @else
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <x-input model="customerPhone" modifier="live.debounce.500ms" label="Phone" type="tel" inputmode="tel" :required="$orderType === 'delivery'" placeholder="03xx xxxxxxx" />
                                @if ($this->knownCustomer)
                                    <div class="fs-12 text-success mt-1"><i class="ri-user-follow-line"></i> {{ $this->knownCustomer->name }} &middot; {{ $this->knownCustomer->orders_count }} {{ \Illuminate\Support\Str::plural('order', $this->knownCustomer->orders_count) }}</div>
                                @endif
                            </div>
                            <div class="col-sm-6">
                                <x-input model="customerName" label="Name" placeholder="{{ $orderType === 'takeaway' ? 'For calling out the order' : '' }}" />
                            </div>
                            @if ($orderType === 'delivery')
                                <div class="col-12">
                                    <x-textarea model="customerAddress" label="Delivery address" rows="2" required />
                                </div>
                                <div class="col-sm-6">
                                    <x-input model="deliveryCharge" label="Delivery charge" inputmode="decimal" :prepend="$shop->currency_symbol" :placeholder="$this->plain($shop->default_delivery_charge)" />
                                </div>
                            @endif
                        </div>
                    @endif

                    <x-input model="orderNote" label="Order note" placeholder="Shown on kitchen tickets" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="saveDetails">{{ $order ? 'Save' : 'Done' }}</button>
                </div>
            </form>
        </x-modal>

        {{-- Line: note / discount --}}
        <x-modal wire:model="showLine" :title="$lineItemId ? 'Discount' : 'Edit item'">
            <form wire:submit="saveLine" novalidate>
                <div class="modal-body vstack gap-3">
                    @unless ($lineItemId)
                        <x-input model="lineNote" label="Note for the kitchen" placeholder="e.g. no onions" />
                    @endunless
                    @if ($canDiscount)
                        <div>
                            <label class="form-label">Discount on this item</label>
                            <div class="input-group">
                                <select class="form-select form-select-touch" style="max-width: 9rem" wire:model="lineDiscountType">
                                    <option value="">None</option>
                                    <option value="percent">Percent %</option>
                                    <option value="fixed">Amount {{ $shop->currency_symbol }}</option>
                                </select>
                                <input type="text" inputmode="decimal" class="form-control form-control-touch @error('lineDiscountValue') is-invalid @enderror" wire:model="lineDiscountValue" placeholder="0">
                            </div>
                            @error('lineDiscountValue')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($lineKey)
                        <button type="button" class="btn btn-light text-danger btn-touch me-auto" wire:click="removeLine('{{ $lineKey }}')" x-on:click="$wire.showLine = false"><i class="ri-delete-bin-line"></i>Remove</button>
                    @endif
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-touch">Save</button>
                </div>
            </form>
        </x-modal>

        {{-- Void --}}
        @php($voidItem = $voidItemId ? $order?->items->firstWhere('id', $voidItemId) : null)
        <x-modal wire:model="showVoid" title="Void item">
            <form wire:submit="confirmVoid" novalidate>
                <div class="modal-body vstack gap-3">
                    @if ($voidItem)
                        <div class="bg-light rounded-3 p-3">
                            <div class="fw-semibold">{{ $voidItem->displayName() }}</div>
                            <div class="fs-13 text-muted">{{ $voidItem->activeQuantity() }} on the order &middot; a VOID ticket goes to the kitchen</div>
                        </div>
                        @if ($voidItem->activeQuantity() > 1)
                            <x-input model="voidQty" label="How many to void" type="number" inputmode="numeric" min="1" :max="$voidItem->activeQuantity()" class="col-6" />
                        @endif
                    @endif
                    <div>
                        <x-input model="voidReason" label="Reason" required placeholder="Why is this being voided?" />
                        <div class="hstack flex-wrap gap-1 mt-2">
                            @foreach ($this::VOID_REASONS as $reason)
                                <button type="button" class="btn btn-sm btn-light" x-on:click="$wire.voidReason = @js($reason)">{{ $reason }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Keep it</button>
                    <button type="submit" class="btn btn-danger btn-touch" wire:loading.attr="disabled" wire:target="confirmVoid"><i class="ri-arrow-go-back-line"></i>Void</button>
                </div>
            </form>
        </x-modal>

        {{-- Cancel order --}}
        <x-modal wire:model="showCancel" title="Cancel order">
            <form wire:submit="confirmCancel" novalidate>
                <div class="modal-body vstack gap-3">
                    <p class="mb-0">Cancel <strong>{{ $order?->order_number }}</strong>? Every item is voided, the kitchen gets a VOID ticket{{ $order?->table ? ' and '.$order->table->name.' is freed' : '' }}.</p>
                    <x-input model="cancelReason" label="Reason" required placeholder="e.g. Customer left" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Keep order</button>
                    <button type="submit" class="btn btn-danger btn-touch" wire:loading.attr="disabled" wire:target="confirmCancel">Cancel order</button>
                </div>
            </form>
        </x-modal>

        {{-- Rider --}}
        <x-modal wire:model="showDispatch" title="Send out for delivery">
            <form wire:submit="confirmDispatch" novalidate>
                <div class="modal-body vstack gap-3">
                    @if ($this->riders->isEmpty())
                        <p class="text-muted mb-0">No riders yet. Add them under Setup → Waiters &amp; Riders.</p>
                    @else
                        <div class="sfms-filter-tabs flex-wrap">
                            @foreach ($this->riders as $r)
                                <button type="button" class="btn" wire:key="r-{{ $r->id }}"
                                        x-bind:class="$wire.riderId == '{{ $r->id }}' ? 'btn-primary' : 'btn-light'"
                                        x-on:click="$wire.riderId = '{{ $r->id }}'">{{ $r->name }}</button>
                            @endforeach
                        </div>
                        @error('riderId')<div class="invalid-feedback d-block">Choose a rider.</div>@enderror
                        <p class="fs-13 text-muted mb-0">The bill prints for the rider to collect payment.</p>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-touch" @disabled($this->riders->isEmpty())><i class="ri-e-bike-2-line"></i>Send out</button>
                </div>
            </form>
        </x-modal>

        {{-- Checkout --}}
        @php($bill = $showCheckout ? $this->checkoutPreview : null)
        <x-modal wire:model="showCheckout" :title="'Payment'.($order ? ' · '.$order->order_number : '')" size="lg" static>
            @if ($bill)
                @php($paidSoFar = $this->paymentsTotal())
                @php($remaining = \App\Support\Money::sub($bill->grand_total, $paidSoFar))
                <form wire:submit="completeCheckout" novalidate>
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-md-5">
                                <div class="vstack gap-1 fs-14">
                                    <div class="hstack justify-content-between"><span class="text-muted">Subtotal</span><span>{{ $money($bill->subtotal) }}</span></div>
                                    @if ((float) $bill->item_discount_total > 0)
                                        <div class="hstack justify-content-between text-success"><span>Item discounts</span><span>−{{ $money($bill->item_discount_total) }}</span></div>
                                    @endif
                                    @if ((float) $bill->discount_amount > 0)
                                        <div class="hstack justify-content-between text-success"><span>Order discount</span><span>−{{ $money($bill->discount_amount) }}</span></div>
                                    @endif
                                    @if ($bill->isDineIn())
                                        <div class="hstack justify-content-between"><span class="text-muted">Service charge</span><span>{{ $money($bill->service_charge) }}</span></div>
                                    @endif
                                    @if ($bill->isDelivery())
                                        <div class="hstack justify-content-between"><span class="text-muted">Delivery</span><span>{{ $money($bill->delivery_charge) }}</span></div>
                                    @endif
                                    <div class="hstack justify-content-between fs-3 fw-bold border-top pt-2 mt-1"><span>Total</span><span>{{ $money($bill->grand_total) }}</span></div>
                                </div>

                                @if ($canDiscount || $bill->isDelivery())
                                    <div class="border rounded-3 p-3 mt-3 vstack gap-3">
                                        @if ($canDiscount)
                                            <div>
                                                <label class="form-label fs-13 mb-1">Order discount</label>
                                                <div class="input-group input-group-sm">
                                                    <select class="form-select" style="max-width: 8rem" wire:model.live="discountType">
                                                        <option value="">None</option>
                                                        <option value="percent">%</option>
                                                        <option value="fixed">{{ $shop->currency_symbol }}</option>
                                                    </select>
                                                    <input type="text" inputmode="decimal" class="form-control" wire:model.live.debounce.400ms="discountValue" placeholder="0" @disabled($discountType === '')>
                                                </div>
                                            </div>
                                            @if ($bill->isDineIn() && (float) $order->service_charge_value > 0)
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="waive-service" wire:model.live="waiveService">
                                                    <label class="form-check-label fs-13" for="waive-service">Remove service charge</label>
                                                </div>
                                            @endif
                                        @endif
                                        @if ($bill->isDelivery())
                                            <div>
                                                <label class="form-label fs-13 mb-1">Delivery charge</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">{{ $shop->currency_symbol }}</span>
                                                    <input type="text" inputmode="decimal" class="form-control" wire:model.live.debounce.400ms="checkoutDelivery">
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="col-md-7">
                                <div class="vstack gap-3">
                                    @foreach ($payments as $i => $payment)
                                        @php($account = $this->accounts->firstWhere('id', (int) $payment['account_id']))
                                        @php($isCash = $account?->type === 'cash')
                                        <div class="border rounded-3 p-3" wire:key="pay-{{ $i }}">
                                            <div class="hstack gap-2 mb-2">
                                                <select class="form-select form-select-touch" wire:model.live="payments.{{ $i }}.account_id" aria-label="Account">
                                                    @foreach ($this->accounts as $a)
                                                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                                                    @endforeach
                                                </select>
                                                @if (count($payments) > 1)
                                                    <button type="button" class="btn btn-light text-danger flex-shrink-0" wire:click="removePayment({{ $i }})" aria-label="Remove payment"><i class="ri-close-line"></i></button>
                                                @endif
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label class="form-label fs-12 mb-1">Amount</label>
                                                    <div class="input-group">
                                                        <input type="text" inputmode="decimal" class="form-control form-control-touch fw-semibold @error('payments.'.$i.'.amount') is-invalid @enderror" wire:model.live.debounce.400ms="payments.{{ $i }}.amount">
                                                        @if (count($payments) > 1)
                                                            <button type="button" class="btn btn-light" wire:click="fillRemaining({{ $i }})" title="Put the rest here"><i class="ri-equalizer-line"></i></button>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    @if ($isCash)
                                                        <label class="form-label fs-12 mb-1">Cash received</label>
                                                        <input type="text" inputmode="decimal" class="form-control form-control-touch @error('payments.'.$i.'.tendered') is-invalid @enderror" wire:model.live.debounce.400ms="payments.{{ $i }}.tendered" placeholder="{{ $payment['amount'] }}">
                                                    @else
                                                        <label class="form-label fs-12 mb-1">Reference</label>
                                                        <input type="text" class="form-control form-control-touch" wire:model.blur="payments.{{ $i }}.reference" placeholder="Txn ID (optional)">
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($isCash && is_numeric($payment['amount']))
                                                @php($amountDue = (float) $payment['amount'])
                                                <div class="hstack flex-wrap gap-1 mt-2">
                                                    @foreach (collect([500, 1000, 5000])->map(fn ($n) => (int) (ceil($amountDue / $n) * $n))->push((int) ceil($amountDue))->unique()->sort()->filter(fn ($n) => $n >= $amountDue && $n > 0)->take(4) as $note)
                                                        <button type="button" class="btn btn-sm btn-light" wire:click="setTendered({{ $i }}, '{{ $note }}')">{{ number_format($note) }}</button>
                                                    @endforeach
                                                </div>
                                                @if (is_numeric($payment['tendered']) && (float) $payment['tendered'] >= $amountDue && $amountDue > 0)
                                                    <div class="hstack justify-content-between mt-2 bg-success-subtle text-success rounded-2 px-2 py-1 fw-semibold">
                                                        <span>Change</span><span>{{ $money((float) $payment['tendered'] - $amountDue) }}</span>
                                                    </div>
                                                @elseif (is_numeric($payment['tendered']) && (float) $payment['tendered'] < $amountDue)
                                                    <div class="fs-12 text-danger mt-1">Less than the amount.</div>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach

                                    <div class="hstack justify-content-between">
                                        <button type="button" class="btn btn-sm btn-light" wire:click="addPayment" @disabled($this->accounts->count() < 1)>
                                            <i class="ri-add-line"></i>Split payment
                                        </button>
                                        @if (\App\Support\Money::isZero($remaining))
                                            <span class="text-success fw-semibold fs-13"><i class="ri-checkbox-circle-line"></i> Fully covered</span>
                                        @elseif (\App\Support\Money::isPositive($remaining))
                                            <span class="text-danger fw-semibold fs-13">{{ $money($remaining) }} still to pay</span>
                                        @else
                                            <span class="text-warning fw-semibold fs-13">{{ $money(abs((float) $remaining)) }} too much</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Not now</button>
                        <button type="submit" class="btn btn-success btn-touch px-4" wire:loading.attr="disabled" wire:target="completeCheckout" @disabled(! \App\Support\Money::isZero($remaining))>
                            <i class="ri-check-double-line"></i>Complete &middot; {{ $money($bill->grand_total) }}
                        </button>
                    </div>
                </form>
            @endif
        </x-modal>
    @endif
</div>
