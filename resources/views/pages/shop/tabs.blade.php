<?php

use App\Exceptions\PosException;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Waiter;
use App\Services\BusinessDays;
use App\Services\Orders;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Open Tabs: every table (free or occupied), open takeaway orders and the
 * delivery queue. Refreshes itself every 15 seconds until live updates land (M6).
 */
new #[Layout('layouts::shop')] #[Title('Open Tabs')] class extends Component
{
    use \App\Livewire\Concerns\RefreshesLive;

    public string $waiter = '';

    public bool $showDispatch = false;
    #[Locked]
    public ?int $dispatchOrderId = null;
    public string $riderId = '';

    /** Minutes after which an open tab is highlighted. */
    public const LATE_AFTER = 45;

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
    public function open()
    {
        return Order::query()->open()
            ->with(['table', 'waiter', 'rider', 'items'])
            ->when($this->waiter !== '', fn ($q) => $q->where('waiter_id', (int) $this->waiter))
            ->orderBy('opened_at')
            ->get();
    }

    #[Computed]
    public function tables()
    {
        return DiningTable::query()->where('is_active', true)->ordered()->get();
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

    public function openDispatch(int $orderId): void
    {
        $order = Order::query()->open()->where('type', 'delivery')->find($orderId);

        if (! $order) {
            return;
        }

        $this->dispatchOrderId = $order->id;
        $this->riderId = (string) ($order->rider_id ?? '');
        $this->resetValidation();
        $this->showDispatch = true;
    }

    public function confirmDispatch(Orders $orders): void
    {
        $this->validate(['riderId' => ['required', 'integer']], [], ['riderId' => 'rider']);

        $order = Order::query()->find($this->dispatchOrderId);

        try {
            $order = $orders->dispatchRider($order ?? new Order, (int) $this->riderId);
        } catch (PosException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->showDispatch = false;
        unset($this->open);
        $this->dispatch('sfms-print', url: route('shop.print.receipt', ['order' => $order->id]), kind: 'receipt');
        $this->dispatch('toast', type: 'success', message: $order->order_number.' is out for delivery.');
    }
};
?>

@php
    $shop = $this->shop;
    $open = $this->open;
    $dineIn = $open->where('type', 'dine_in');
    $byTable = $dineIn->whereNotNull('dining_table_id')->keyBy('dining_table_id');
    $noTable = $dineIn->whereNull('dining_table_id');
    $takeaway = $open->where('type', 'takeaway');
    $delivery = $open->where('type', 'delivery');
    $areas = $this->tables->groupBy(fn ($t) => $t->area ?: 'Tables');
    $occupied = $this->tables->filter(fn ($t) => $byTable->has($t->id))->count();
@endphp

{{-- Live updates refresh this board instantly; the slow poll keeps the minutes current and covers Reverb being down. --}}
<div wire:poll.60s.visible>
    <x-page-header title="Open Tabs" subtitle="Tables, takeaway and delivery orders that haven't been paid yet.">
        <x-slot:actions>
            <x-live-status />
            <a href="{{ route('shop.pos', ['type' => 'takeaway']) }}" wire:navigate class="btn btn-light btn-touch"><i class="ri-shopping-bag-3-line"></i>Takeaway</a>
            <a href="{{ route('shop.pos', ['type' => 'delivery']) }}" wire:navigate class="btn btn-light btn-touch"><i class="ri-e-bike-2-line"></i>Delivery</a>
            <a href="{{ route('shop.pos') }}" wire:navigate class="btn btn-primary btn-touch"><i class="ri-add-line"></i>New order</a>
        </x-slot:actions>
    </x-page-header>

    @unless ($this->day)
        <div class="alert alert-warning hstack gap-2 sfms-banner">
            <i class="ri-error-warning-line fs-18"></i>
            <span>The business day is closed. @can('day.open_close')<a href="{{ route('shop.day') }}" wire:navigate class="alert-link">Open the day</a> to take orders.@else Ask a manager to open it.@endcan</span>
        </div>
    @endunless

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-kpi label="Open orders" :value="$open->count()" icon="ri-restaurant-2-line" tone="warning" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Unpaid" :value="$shop->money(\App\Support\Money::sum($open->pluck('grand_total')))" icon="ri-money-dollar-circle-line" tone="danger" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Tables in use" :value="$occupied.' / '.$this->tables->count()" icon="ri-layout-grid-line" tone="primary" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Deliveries out" :value="$delivery->where('delivery_status', 'out')->count().' / '.$delivery->count()" icon="ri-e-bike-2-line" tone="info" /></div>
    </div>

    @if ($this->waiters->isNotEmpty())
        <div class="sfms-filter-tabs mb-3">
            <button type="button" class="btn btn-sm {{ $waiter === '' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('waiter', '')">All waiters</button>
            @foreach ($this->waiters as $w)
                <button type="button" class="btn btn-sm {{ $waiter === (string) $w->id ? 'btn-primary' : 'btn-light' }}" wire:click="$set('waiter', '{{ $w->id }}')" wire:key="wf-{{ $w->id }}">{{ $w->name }}</button>
            @endforeach
        </div>
    @endif

    {{-- Tables --}}
    @foreach ($areas as $area => $tables)
        <h6 class="text-muted text-uppercase fs-12 fw-bold mb-2 {{ $loop->first ? '' : 'mt-4' }}">{{ $area }}</h6>
        <div class="sfms-tab-grid">
            @foreach ($tables as $table)
                @php($tab = $byTable->get($table->id))
                @if ($tab)
                    <a href="{{ route('shop.pos.order', ['order' => $tab->id]) }}" wire:navigate wire:key="t-{{ $table->id }}"
                       class="sfms-tab-card {{ $tab->minutesOpen() >= $this::LATE_AFTER ? 'is-late' : 'is-busy' }}">
                        <div class="hstack justify-content-between">
                            <span class="sfms-tab-name">{{ $table->name }}</span>
                            <span class="badge bg-primary">#{{ $tab->token_number }}</span>
                        </div>
                        <div class="fw-semibold">{{ $shop->money($tab->grand_total) }}</div>
                        <div class="fs-12 text-muted text-truncate">{{ $tab->waiter?->name ?? 'No waiter' }}@if ($tab->guests) &middot; {{ $tab->guests }} guests @endif</div>
                        <div class="fs-12 mt-auto {{ $tab->minutesOpen() >= $this::LATE_AFTER ? 'text-danger fw-semibold' : 'text-muted' }}"><i class="ri-time-line"></i> {{ $tab->durationLabel() }}</div>
                    </a>
                @elseif ($waiter === '')
                    <a href="{{ route('shop.pos', ['table' => $table->id]) }}" wire:navigate wire:key="t-{{ $table->id }}" class="sfms-tab-card is-free">
                        <span class="sfms-tab-name">{{ $table->name }}</span>
                        <span class="fs-12">Free{{ $table->capacity ? ' · '.$table->capacity.' seats' : '' }}</span>
                        <span class="fs-12 mt-auto text-primary"><i class="ri-add-line"></i> Start order</span>
                    </a>
                @endif
            @endforeach
        </div>
    @endforeach

    @if ($this->tables->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-layout-grid-line" title="No tables yet">
                Add tables under Setup → Tables to run dine-in tabs by table. You can still open dine-in orders by waiter.
            </x-empty-state>
        </div>
    @endif

    @if ($noTable->isNotEmpty())
        <h6 class="text-muted text-uppercase fs-12 fw-bold mb-2 mt-4">Dine-in without a table</h6>
        <div class="sfms-tab-grid">
            @foreach ($noTable as $tab)
                <a href="{{ route('shop.pos.order', ['order' => $tab->id]) }}" wire:navigate wire:key="nt-{{ $tab->id }}" class="sfms-tab-card is-busy">
                    <div class="hstack justify-content-between">
                        <span class="sfms-tab-name text-truncate">{{ $tab->waiter?->name ?? 'Dine-in' }}</span>
                        <span class="badge bg-primary">#{{ $tab->token_number }}</span>
                    </div>
                    <div class="fw-semibold">{{ $shop->money($tab->grand_total) }}</div>
                    <div class="fs-12 text-muted mt-auto"><i class="ri-time-line"></i> {{ $tab->durationLabel() }}</div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="row g-3 mt-2">
        {{-- Takeaway --}}
        <div class="col-lg-6">
            <div class="card h-100 mb-0">
                <div class="card-header hstack justify-content-between">
                    <h5 class="card-title mb-0"><i class="ri-shopping-bag-3-line me-1"></i>Takeaway</h5>
                    <span class="badge bg-light text-muted">{{ $takeaway->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @forelse ($takeaway as $tab)
                        <a href="{{ route('shop.pos.order', ['order' => $tab->id]) }}" wire:navigate wire:key="ta-{{ $tab->id }}"
                           class="hstack gap-3 px-3 py-2 border-bottom text-reset text-decoration-none sfms-setup-step">
                            <span class="avatar avatar-sm avatar-title rounded-circle bg-primary-subtle text-primary border-0 fw-bold">{{ $tab->token_number }}</span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold text-truncate">{{ $tab->customer_name ?: $tab->order_number }}</div>
                                <div class="fs-12 text-muted">{{ $tab->order_number }} &middot; {{ $tab->itemCount() }} items &middot; {{ $tab->durationLabel() }}</div>
                            </div>
                            <span class="fw-semibold">{{ $shop->money($tab->grand_total) }}</span>
                        </a>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">No unpaid takeaway orders.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Delivery --}}
        <div class="col-lg-6">
            <div class="card h-100 mb-0">
                <div class="card-header hstack justify-content-between">
                    <h5 class="card-title mb-0"><i class="ri-e-bike-2-line me-1"></i>Delivery</h5>
                    <span class="badge bg-light text-muted">{{ $delivery->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @forelse ($delivery as $tab)
                        <div class="px-3 py-2 border-bottom" wire:key="de-{{ $tab->id }}">
                            <div class="hstack gap-3">
                                <span class="avatar avatar-sm avatar-title rounded-circle bg-info-subtle text-info border-0 fw-bold">{{ $tab->token_number }}</span>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="fw-semibold text-truncate">{{ $tab->customer_name ?: 'Customer' }} <span class="fw-normal text-muted fs-13">{{ $tab->customer_phone }}</span></div>
                                    <div class="fs-12 text-muted text-truncate">{{ $tab->customer_address }}</div>
                                </div>
                                <span class="fw-semibold">{{ $shop->money($tab->grand_total) }}</span>
                            </div>
                            <div class="hstack flex-wrap gap-2 mt-2 ms-5">
                                @if ($tab->delivery_status === 'out')
                                    <span class="badge bg-info-subtle text-info"><i class="ri-e-bike-2-line"></i> Out with {{ $tab->rider?->name }} &middot; {{ $tab->dispatched_at?->diffForHumans(short: true) }}</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning"><i class="ri-fire-line"></i> Preparing &middot; {{ $tab->durationLabel() }}</span>
                                @endif
                                <span class="ms-auto"></span>
                                <button type="button" class="btn btn-sm btn-light" wire:click="openDispatch({{ $tab->id }})">{{ $tab->rider_id ? 'Change rider' : 'Send out' }}</button>
                                <a href="{{ route('shop.pos.order', ['order' => $tab->id, 'pay' => 1]) }}" wire:navigate class="btn btn-sm btn-success">Paid</a>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">No deliveries in progress.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <x-modal wire:model="showDispatch" title="Send out for delivery">
        <form wire:submit="confirmDispatch" novalidate>
            <div class="modal-body vstack gap-3">
                @if ($this->riders->isEmpty())
                    <p class="text-muted mb-0">No riders yet. Add them under Setup → Waiters &amp; Riders.</p>
                @else
                    <div class="sfms-filter-tabs flex-wrap">
                        @foreach ($this->riders as $r)
                            <button type="button" class="btn" wire:key="rd-{{ $r->id }}"
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
</div>
