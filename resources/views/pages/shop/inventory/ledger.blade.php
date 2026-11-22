<?php

use App\Models\Ingredient;
use App\Models\Kot;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Stock Ledger')] class extends Component
{
    use \App\Livewire\Concerns\RefreshesLive;

    protected function liveTopics(): array
    {
        return ['stock'];
    }

    use WithPagination;

    #[Url(except: '')]
    public string $ingredient = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $date = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['ingredient', 'type', 'date'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('ingredient', 'type', 'date');
        $this->resetPage();
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function ingredients()
    {
        return Ingredient::query()->orderBy('name')->get();
    }

    #[Computed]
    public function selected(): ?Ingredient
    {
        return $this->ingredient !== '' ? $this->ingredients->firstWhere('id', (int) $this->ingredient) : null;
    }

    #[Computed]
    public function movements()
    {
        $query = StockMovement::query()
            ->with(['ingredient', 'user', 'source'])
            ->when($this->selected, fn ($q) => $q->where('ingredient_id', $this->selected->id))
            ->when(array_key_exists($this->type, StockMovement::TYPES), fn ($q) => $q->where('type', $this->type));

        if ($this->date !== '' && strtotime($this->date)) {
            $start = Carbon::parse($this->date, $this->shop->timezone)->startOfDay();
            $query->whereBetween('created_at', [$start->copy()->utc(), $start->copy()->endOfDay()->utc()]);
        }

        return $query->latest('id')->paginate(25);
    }

    /** Where a movement came from: "BLG-000123 · KOT #4", "BLG-PUR-000002". */
    public function sourceLink(StockMovement $movement): array
    {
        $source = $movement->source;

        return match (true) {
            $source instanceof Kot => [
                'label' => ($movement->note ?: 'Order').' · '.$source->label(),
                'url' => auth('shop')->user()->can('orders.view') ? route('shop.orders.show', ['order' => $source->order_id]) : null,
            ],
            $source instanceof Purchase => ['label' => $source->voucher_number, 'url' => route('shop.purchases.index', ['search' => $source->voucher_number])],
            default => ['label' => $movement->note ?: '—', 'url' => null],
        };
    }
};
?>

@php
    $shop = $this->shop;
    $tz = $shop->timezone;
@endphp

<div>
    <x-page-header title="Stock ledger" :subtitle="$this->selected
        ? $this->selected->name.' · in stock '.$this->selected->stockLabel()
        : 'Every change to stock: purchases, sales, voids, counts and wastage.'" />

    <div class="card mb-0">
        <div class="card-header">
            <div class="row g-2">
                <div class="col-md-4">
                    <select class="form-select form-select-touch" wire:model.live="ingredient" aria-label="Ingredient">
                        <option value="">All ingredients</option>
                        @foreach ($this->ingredients as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select form-select-touch" wire:model.live="type" aria-label="Type">
                        <option value="">All changes</option>
                        @foreach (\App\Models\StockMovement::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <input type="date" class="form-control form-control-touch" wire:model.live="date" aria-label="Date">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-light btn-touch w-100" wire:click="clearFilters">Clear</button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            @if ($this->movements->isEmpty())
                <x-empty-state icon="ri-git-commit-line" title="No stock changes">Purchases, sales and counts will appear here.</x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>When</th><th>Ingredient</th><th>Change</th><th class="text-end">Quantity</th><th class="text-end">Balance</th><th>From</th><th>By</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->movements as $movement)
                                @php($unit = $movement->ingredient?->unit)
                                @php($link = $this->sourceLink($movement))
                                <tr wire:key="sm-{{ $movement->id }}">
                                    <td data-label="When" class="text-muted fs-13">{{ $movement->created_at->timezone($tz)->format('d M, h:i A') }}</td>
                                    <td class="td-primary fw-medium">{{ $movement->ingredient?->name }}</td>
                                    <td data-label="Change"><span class="badge bg-{{ $movement->tone() }}-subtle text-{{ $movement->tone() }}">{{ $movement->typeLabel() }}</span></td>
                                    <td data-label="Quantity" class="text-end fw-semibold {{ (float) $movement->quantity < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ (float) $movement->quantity < 0 ? '' : '+' }}{{ \App\Support\Quantity::format($movement->quantity, $unit) }}
                                    </td>
                                    <td data-label="Balance" class="text-end {{ (float) $movement->balance_after < 0 ? 'text-danger' : '' }}">{{ \App\Support\Quantity::format($movement->balance_after, $unit) }}</td>
                                    <td data-label="From" class="fs-13">
                                        @if ($link['url'])
                                            <a href="{{ $link['url'] }}" wire:navigate>{{ $link['label'] }}</a>
                                        @else
                                            {{ $link['label'] }}
                                        @endif
                                    </td>
                                    <td data-label="By" class="fs-13">{{ $movement->user?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->movements->hasPages())
                    <div class="p-3 border-top">{{ $this->movements->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
