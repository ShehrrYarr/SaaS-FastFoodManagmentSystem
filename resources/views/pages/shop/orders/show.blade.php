<?php

use App\Models\Order;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::shop')] class extends Component
{
    use \App\Livewire\Concerns\RefreshesLive;

    /** Reload only when this order changed on another screen. */
    protected function refreshLive(array $topics, array $orders): void
    {
        if (! in_array($this->orderId, $orders, true)) {
            $this->skipRender();
        }
    }

    #[Locked]
    public int $orderId;

    public function mount(Order $order): void
    {
        $this->orderId = $order->id;
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with([
            'items.modifiers', 'items.voids.user', 'payments.account', 'payments.receivedBy',
            'kots.createdBy', 'table', 'waiter', 'rider', 'customer', 'openedBy', 'closedBy',
        ])->findOrFail($this->orderId);
    }

    public function reprint(string $what, ?int $kotId = null): void
    {
        $url = $what === 'kot'
            ? ($this->order->kots->firstWhere('id', $kotId) ? route('shop.print.kot', ['kot' => $kotId]) : null)
            : route('shop.print.receipt', ['order' => $this->order->id]);

        if ($url) {
            $this->js('sfmsPrint.url('.json_encode($url).')');
        }
    }

    public function render()
    {
        return $this->view()->title('Order '.$this->order->order_number);
    }
};
?>

@php
    $shop = $this->shop;
    $order = $this->order;
    $tz = $shop->timezone;
    $badge = $order->statusBadge();
    $money = fn ($v) => $shop->money($v);
    $voids = $order->items->flatMap(fn ($item) => $item->voids->map(fn ($v) => [$item, $v]))->sortBy(fn ($pair) => $pair[1]->id);
@endphp

<div>
    <x-page-header :title="$order->order_number" :breadcrumbs="['Orders' => route('shop.orders.index'), $order->order_number => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-light btn-touch" wire:click="reprint('receipt')"><i class="ri-printer-line"></i>{{ $order->status === 'completed' ? 'Reprint receipt' : 'Print bill' }}</button>
            @if ($order->isOpen())
                @can('pos.use')
                    <a href="{{ route('shop.pos.order', ['order' => $order->id]) }}" wire:navigate class="btn btn-primary btn-touch"><i class="ri-shopping-basket-2-line"></i>Open in POS</a>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header hstack flex-wrap gap-2 justify-content-between">
                    <div class="hstack gap-2">
                        <span class="badge {{ $badge['class'] }} fs-13">{{ $badge['label'] }}</span>
                        <span class="badge bg-primary fs-13">Token {{ $order->token_number }}</span>
                        <span class="text-muted"><i class="{{ \App\Models\Order::typeIcon($order->type) }}"></i> {{ $order->typeLabel() }}</span>
                    </div>
                    @if ($order->deliveryStatusLabel())
                        <span class="badge bg-info-subtle text-info">{{ $order->deliveryStatusLabel() }}</span>
                    @endif
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 table-stack">
                            <thead class="table-light">
                                <tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Each</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($order->items as $item)
                                    <tr wire:key="i-{{ $item->id }}" class="{{ $item->isFullyVoided() ? 'opacity-50' : '' }}">
                                        <td class="td-primary">
                                            <div class="cell-stack">
                                                <div class="fw-medium {{ $item->isFullyVoided() ? 'text-decoration-line-through' : '' }}">{{ $item->displayName() }}</div>
                                                @if ($item->modifiers->isNotEmpty())<div class="fs-12 text-muted">+ {{ $item->modifiers->pluck('name')->implode(', ') }}</div>@endif
                                                @if ($item->combo_snapshot)<div class="fs-12 text-muted">{{ collect($item->combo_snapshot)->map(fn ($c) => $c['quantity'].'× '.$c['name'])->implode(' · ') }}</div>@endif
                                                @if ($item->note)<div class="fs-12 text-warning-emphasis">“{{ $item->note }}”</div>@endif
                                                @if ((float) $item->discount_amount > 0)<div class="fs-12 text-success">Discount −{{ $money($item->discount_amount) }}</div>@endif
                                            </div>
                                        </td>
                                        <td data-label="Qty" class="text-end">
                                            {{ $item->activeQuantity() }}
                                            @if ($item->voided_quantity)<div class="fs-12 text-danger">{{ $item->voided_quantity }} voided</div>@endif
                                        </td>
                                        <td data-label="Each" class="text-end">{{ $money($item->eachPrice()) }}</td>
                                        <td data-label="Total" class="text-end fw-semibold">{{ $money($item->line_total) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="ms-auto vstack gap-1" style="max-width: 22rem">
                        <div class="hstack justify-content-between"><span class="text-muted">Subtotal</span><span>{{ $money($order->subtotal) }}</span></div>
                        @if ((float) $order->item_discount_total > 0)
                            <div class="hstack justify-content-between text-success"><span>Item discounts</span><span>−{{ $money($order->item_discount_total) }}</span></div>
                        @endif
                        @if ((float) $order->discount_amount > 0)
                            <div class="hstack justify-content-between text-success"><span>Order discount{{ $order->discount_type === 'percent' ? ' ('.rtrim(rtrim($order->discount_value, '0'), '.').'%)' : '' }}</span><span>−{{ $money($order->discount_amount) }}</span></div>
                        @endif
                        @if ((float) $order->service_charge > 0)
                            <div class="hstack justify-content-between"><span class="text-muted">Service charge</span><span>{{ $money($order->service_charge) }}</span></div>
                        @endif
                        @if ($order->isDelivery())
                            <div class="hstack justify-content-between"><span class="text-muted">Delivery</span><span>{{ $money($order->delivery_charge) }}</span></div>
                        @endif
                        <div class="hstack justify-content-between fs-5 fw-bold border-top pt-2 mt-1"><span>Total</span><span>{{ $money($order->grand_total) }}</span></div>
                    </div>
                </div>
            </div>

            @if ($order->payments->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Payments</h5></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 table-stack">
                                <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount</th><th class="text-end">Received</th><th class="text-end">Change</th><th>Reference</th><th>By</th></tr></thead>
                                <tbody>
                                    @foreach ($order->payments as $payment)
                                        <tr>
                                            <td class="td-primary fw-medium">{{ $payment->account?->name }}</td>
                                            <td data-label="Amount" class="text-end fw-semibold">{{ $money($payment->amount) }}</td>
                                            <td data-label="Received" class="text-end">{{ $payment->tendered !== null ? $money($payment->tendered) : '—' }}</td>
                                            <td data-label="Change" class="text-end">{{ (float) $payment->change_given > 0 ? $money($payment->change_given) : '—' }}</td>
                                            <td data-label="Reference">{{ $payment->reference ?: '—' }}</td>
                                            <td data-label="By">{{ $payment->receivedBy?->name ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            @if ($voids->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Voids</h5></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 table-stack">
                                <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
                                <tbody>
                                    @foreach ($voids as [$item, $void])
                                        <tr>
                                            <td class="td-primary fw-medium">{{ $item->displayName() }}</td>
                                            <td data-label="Qty" class="text-end">{{ $void->quantity }}</td>
                                            <td data-label="Reason">{{ $void->reason }}</td>
                                            <td data-label="By">{{ $void->user?->name ?? '—' }}</td>
                                            <td data-label="When" class="text-muted fs-13">{{ $void->created_at->timezone($tz)->format('d M, h:i A') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Details</h5></div>
                <div class="card-body">
                    <dl class="row mb-0 gy-2 fs-14">
                        <dt class="col-5 text-muted fw-normal">Opened</dt>
                        <dd class="col-7 mb-0">{{ $order->opened_at->timezone($tz)->format('d M, h:i A') }}<div class="fs-12 text-muted">{{ $order->openedBy?->name }}</div></dd>
                        @if ($order->closed_at)
                            <dt class="col-5 text-muted fw-normal">{{ $order->status === 'cancelled' ? 'Cancelled' : 'Paid' }}</dt>
                            <dd class="col-7 mb-0">{{ $order->closed_at->timezone($tz)->format('d M, h:i A') }}<div class="fs-12 text-muted">{{ $order->closedBy?->name }} &middot; {{ $order->durationLabel() }}</div></dd>
                        @endif
                        @if ($order->table)
                            <dt class="col-5 text-muted fw-normal">Table</dt><dd class="col-7 mb-0">{{ $order->table->name }}@if ($order->guests) &middot; {{ $order->guests }} guests @endif</dd>
                        @endif
                        @if ($order->waiter)
                            <dt class="col-5 text-muted fw-normal">Waiter</dt><dd class="col-7 mb-0">{{ $order->waiter->name }}</dd>
                        @endif
                        @if ($order->customer_name || $order->customer_phone)
                            <dt class="col-5 text-muted fw-normal">Customer</dt>
                            <dd class="col-7 mb-0">{{ $order->customer_name }}@if ($order->customer_phone)<div class="fs-13">{{ $order->customer_phone }}</div>@endif</dd>
                        @endif
                        @if ($order->customer_address)
                            <dt class="col-5 text-muted fw-normal">Address</dt><dd class="col-7 mb-0">{{ $order->customer_address }}</dd>
                        @endif
                        @if ($order->rider)
                            <dt class="col-5 text-muted fw-normal">Rider</dt><dd class="col-7 mb-0">{{ $order->rider->name }}</dd>
                        @endif
                        @if ($order->notes)
                            <dt class="col-5 text-muted fw-normal">Note</dt><dd class="col-7 mb-0">{{ $order->notes }}</dd>
                        @endif
                        @if ($order->cancel_reason)
                            <dt class="col-5 text-muted fw-normal">Reason</dt><dd class="col-7 mb-0 text-danger">{{ $order->cancel_reason }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Kitchen tickets</h5></div>
                <div class="card-body p-0">
                    @forelse ($order->kots as $kot)
                        <div class="hstack gap-3 px-3 py-2 border-bottom" wire:key="k-{{ $kot->id }}">
                            <span class="avatar avatar-sm avatar-title rounded-circle border-0 {{ $kot->isVoid() ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary' }}">
                                <i class="{{ $kot->isVoid() ? 'ri-arrow-go-back-line' : 'ri-restaurant-line' }}"></i>
                            </span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-medium">{{ $kot->label() }}</div>
                                <div class="fs-12 text-muted">{{ $kot->created_at->timezone($tz)->format('h:i A') }} &middot; {{ $kot->createdBy?->name }} &middot; printed {{ $kot->printed_count }}×</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-light" wire:click="reprint('kot', {{ $kot->id }})" aria-label="Reprint"><i class="ri-printer-line"></i></button>
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">No tickets.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
