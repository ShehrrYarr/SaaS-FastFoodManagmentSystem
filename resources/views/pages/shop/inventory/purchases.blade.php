<?php

use App\Models\Purchase;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Purchases')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public bool $showDetail = false;
    #[Locked]
    public ?int $detailId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function purchases()
    {
        $term = trim($this->search);

        return Purchase::query()
            ->with(['account', 'createdBy'])
            ->withCount('items')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('voucher_number', 'like', '%'.$term.'%')
                ->orWhere('supplier_name', 'like', '%'.$term.'%')))
            ->latest('purchased_on')->latest('id')
            ->paginate(15);
    }

    #[Computed]
    public function monthTotal(): string
    {
        $start = $this->shop->today()->startOfMonth()->toDateString();

        return Money::of(Purchase::query()->where('purchased_on', '>=', $start)->sum('total'));
    }

    #[Computed]
    public function detail(): ?Purchase
    {
        return $this->detailId ? Purchase::query()->with(['items.ingredient', 'account', 'createdBy'])->find($this->detailId) : null;
    }

    public function open(int $id): void
    {
        $this->detailId = $id;
        unset($this->detail);
        $this->showDetail = $this->detail !== null;
    }
};
?>

@php($shop = $this->shop)

<div>
    <x-page-header title="Purchases" :subtitle="'Stock bought this month: '.$shop->money($this->monthTotal)">
        <x-slot:actions>
            <a href="{{ route('shop.purchases.create') }}" wire:navigate class="btn btn-primary btn-touch"><i class="ri-add-line"></i>New purchase</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-0">
        <div class="card-header">
            <div class="input-group" style="max-width: 24rem">
                <span class="input-group-text bg-body text-muted"><i class="ri-search-line"></i></span>
                <input type="search" class="form-control form-control-touch border-start-0 ps-0" placeholder="Voucher or supplier" wire:model.live.debounce.300ms="search">
            </div>
        </div>
        <div class="card-body p-0">
            @if ($this->purchases->isEmpty())
                <x-empty-state icon="ri-truck-line" title="No purchases yet">Record what you buy and stock goes up automatically, with an average cost per unit.</x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Voucher</th><th>Date</th><th>Supplier</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Paid from</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->purchases as $purchase)
                                <tr wire:key="pu-{{ $purchase->id }}">
                                    <td class="td-primary"><span class="fw-semibold sfms-mono">{{ $purchase->voucher_number }}</span></td>
                                    <td data-label="Date">{{ $purchase->purchased_on->format('d M Y') }}</td>
                                    <td data-label="Supplier">{{ $purchase->supplier_name ?: '—' }}</td>
                                    <td data-label="Items" class="text-end">{{ $purchase->items_count }}</td>
                                    <td data-label="Total" class="text-end fw-semibold">{{ $shop->money($purchase->total) }}</td>
                                    <td data-label="Paid from">
                                        @if ($purchase->account)
                                            {{ $purchase->account->name }}
                                        @else
                                            <span class="badge bg-warning-subtle text-warning">Unpaid</span>
                                        @endif
                                    </td>
                                    <td class="td-actions text-end"><button type="button" class="btn btn-sm btn-light" wire:click="open({{ $purchase->id }})">View</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->purchases->hasPages())
                    <div class="p-3 border-top">{{ $this->purchases->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    <x-modal wire:model="showDetail" :title="$this->detail ? $this->detail->voucher_number : 'Purchase'" size="lg">
        @if ($detail = $this->detail)
            <div class="modal-body">
                <div class="row g-2 fs-14 mb-3">
                    <div class="col-sm-4"><span class="text-muted">Date</span><br>{{ $detail->purchased_on->format('d M Y') }}</div>
                    <div class="col-sm-4"><span class="text-muted">Supplier</span><br>{{ $detail->supplier_name ?: '—' }}</div>
                    <div class="col-sm-4"><span class="text-muted">Paid from</span><br>{{ $detail->account?->name ?? 'Unpaid' }}</div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light"><tr><th>Ingredient</th><th class="text-end">Quantity</th><th class="text-end">Per unit</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            @foreach ($detail->items as $item)
                                <tr>
                                    <td class="td-primary fw-medium">{{ $item->ingredient?->name }}</td>
                                    <td data-label="Quantity" class="text-end">{{ $item->quantityLabel() }}@if ($item->unit !== $item->ingredient?->unit)<div class="fs-12 text-muted">= {{ \App\Support\Quantity::format($item->base_quantity, $item->ingredient?->unit) }}</div>@endif</td>
                                    <td data-label="Per unit" class="text-end">{{ $shop->money($item->unit_cost) }}/{{ $item->unit }}</td>
                                    <td data-label="Amount" class="text-end fw-semibold">{{ $shop->money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr><td colspan="3" class="text-end fw-semibold d-none d-md-table-cell">Total</td><td class="text-end fw-bold">{{ $shop->money($detail->total) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
                @if ($detail->note)<p class="mt-3 mb-0 fs-14"><span class="text-muted">Note:</span> {{ $detail->note }}</p>@endif
                <p class="fs-12 text-muted mt-3 mb-0">Recorded by {{ $detail->createdBy?->name ?? '—' }} on {{ $detail->created_at->timezone($shop->timezone)->format('d M Y, h:i A') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Close</button>
            </div>
        @endif
    </x-modal>
</div>
