<?php

use App\Models\Order;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Orders')] class extends Component
{
    use \App\Livewire\Concerns\RefreshesLive;

    protected function liveTopics(): array
    {
        return ['orders'];
    }

    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        if ($this->date === '' && $this->search === '' && ! request()->has('date')) {
            $this->date = $this->shop->today()->toDateString();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'type', 'date', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    private function filtered()
    {
        $query = Order::query()
            ->when(array_key_exists($this->type, Order::TYPES), fn ($q) => $q->where('type', $this->type))
            ->search($this->search);

        if ($this->date !== '' && strtotime($this->date)) {
            $start = Carbon::parse($this->date, $this->shop->timezone)->startOfDay();
            $query->whereBetween('opened_at', [$start->copy()->utc(), $start->copy()->endOfDay()->utc()]);
        }

        return $query;
    }

    #[Computed]
    public function counts(): array
    {
        $rows = $this->filtered()->selectRaw('status, COUNT(*) as n, SUM(grand_total) as total')->groupBy('status')->get()->keyBy('status');

        return [
            'all' => (int) $rows->sum('n'),
            'open' => (int) ($rows['open']->n ?? 0),
            'completed' => (int) ($rows['completed']->n ?? 0),
            'cancelled' => (int) ($rows['cancelled']->n ?? 0),
            'paid_total' => Money::of($rows['completed']->total ?? 0),
        ];
    }

    #[Computed]
    public function orders()
    {
        return $this->filtered()
            ->when(array_key_exists($this->status, Order::STATUSES), fn ($q) => $q->where('status', $this->status))
            ->with(['table', 'waiter'])
            ->withSum('items as quantity_ordered', 'quantity')
            ->withSum('items as quantity_voided', 'voided_quantity')
            ->latest('opened_at')->latest('id')
            ->paginate(20);
    }

    public function setDate(string $which): void
    {
        $this->date = match ($which) {
            'today' => $this->shop->today()->toDateString(),
            'yesterday' => $this->shop->today()->subDay()->toDateString(),
            default => '',
        };
        $this->resetPage();
    }
};
?>

@php
    $shop = $this->shop;
    $tz = $shop->timezone;
    $counts = $this->counts;
@endphp

<div>
    <x-page-header title="Orders" :subtitle="$counts['completed'].' paid · '.$shop->money($counts['paid_total']).($date ? ' on '.\Illuminate\Support\Carbon::parse($date)->format('d M Y') : '')">
        @can('pos.use')
            <x-slot:actions>
                <a href="{{ route('shop.pos') }}" wire:navigate class="btn btn-primary btn-touch"><i class="ri-add-line"></i>New order</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-body text-muted"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control form-control-touch border-start-0 ps-0" placeholder="Order no., customer or phone" wire:model.live.debounce.400ms="search">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <input type="date" class="form-control form-control-touch" wire:model.live="date" aria-label="Date">
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select form-select-touch" wire:model.live="type" aria-label="Order type">
                        <option value="">All types</option>
                        @foreach (\App\Models\Order::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 hstack gap-1">
                    <button type="button" class="btn btn-sm btn-light flex-fill" wire:click="setDate('today')">Today</button>
                    <button type="button" class="btn btn-sm btn-light flex-fill" wire:click="setDate('yesterday')">Yesterday</button>
                    <button type="button" class="btn btn-sm btn-light flex-fill" wire:click="setDate('all')" title="All dates">All</button>
                </div>
            </div>

            <div class="sfms-filter-tabs mt-3">
                @foreach (['' => ['All', $counts['all']], 'open' => ['Open', $counts['open']], 'completed' => ['Paid', $counts['completed']], 'cancelled' => ['Cancelled', $counts['cancelled']]] as $value => [$label, $n])
                    <button type="button" class="btn btn-sm {{ $status === $value ? 'btn-primary' : 'btn-light' }}" wire:click="$set('status', '{{ $value }}')">
                        {{ $label }} <span class="badge {{ $status === $value ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary' }} ms-1">{{ $n }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card mb-0">
        <div class="card-body p-0">
            @if ($this->orders->isEmpty())
                <x-empty-state icon="ri-file-list-3-line" title="No orders found">Try another date or clear the search.</x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Order</th><th>Type</th><th>Customer / waiter</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Status</th><th>Time</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->orders as $order)
                                @php($badge = $order->statusBadge())
                                <tr wire:key="o-{{ $order->id }}">
                                    <td class="td-primary">
                                        <a href="{{ route('shop.orders.show', ['order' => $order->id]) }}" wire:navigate class="fw-semibold">{{ $order->order_number }}</a>
                                        <span class="badge bg-light text-muted ms-1">#{{ $order->token_number }}</span>
                                    </td>
                                    <td data-label="Type"><i class="{{ \App\Models\Order::typeIcon($order->type) }} text-muted"></i> {{ $order->typeLabel() }}@if ($order->table) &middot; {{ $order->table->name }}@endif</td>
                                    <td data-label="Customer">{{ $order->customer_name ?: ($order->waiter?->name ?? '—') }}@if ($order->customer_phone)<div class="fs-12 text-muted">{{ $order->customer_phone }}</div>@endif</td>
                                    <td data-label="Items" class="text-end">{{ (int) $order->quantity_ordered - (int) $order->quantity_voided }}</td>
                                    <td data-label="Total" class="text-end fw-semibold">{{ $shop->money($order->grand_total) }}</td>
                                    <td data-label="Status"><span class="badge {{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                                    <td data-label="Time" class="text-muted fs-13">{{ $order->opened_at->timezone($tz)->format('d M, h:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->orders->hasPages())
                    <div class="p-3 border-top">{{ $this->orders->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
