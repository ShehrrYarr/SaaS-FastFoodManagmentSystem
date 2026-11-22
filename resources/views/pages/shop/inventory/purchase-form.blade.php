<?php

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\Ingredient;
use App\Services\Purchases;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('New purchase')] class extends Component
{
    public string $supplierName = '';
    public string $purchasedOn = '';
    public string $accountId = '';
    public string $note = '';
    public array $lines = [];

    public function mount(): void
    {
        $this->purchasedOn = $this->shop->today()->toDateString();
        $this->accountId = (string) ($this->accounts->firstWhere('is_default_cash', true)?->id ?? '');
        $this->addLine();

        // ?ingredient=5 from the Ingredients page
        if ($id = (int) request()->query('ingredient')) {
            $this->lines[0]['ingredient_id'] = (string) $id;
            $this->updatedLines((string) $id, '0.ingredient_id');
        }
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function ingredients()
    {
        return Ingredient::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function accounts()
    {
        return Account::query()->active()->ordered()->get();
    }

    public function addLine(): void
    {
        $this->lines[] = ['ingredient_id' => '', 'quantity' => '', 'unit' => '', 'line_total' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    public function updatedLines(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if ($field === 'ingredient_id' && isset($this->lines[$index])) {
            $this->lines[$index]['unit'] = $this->ingredients->firstWhere('id', (int) $value)?->unit ?? '';
        }
    }

    public function total(): string
    {
        return Money::sum(array_map(fn ($l) => is_numeric($l['line_total'] ?? null) ? $l['line_total'] : 0, $this->lines));
    }

    public function save(Purchases $purchases): void
    {
        $this->validate([
            'supplierName' => ['nullable', 'string', 'max:120'],
            'purchasedOn' => ['required', 'date', 'before_or_equal:'.$this->shop->today()->toDateString()],
            'accountId' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'purchasedOn.before_or_equal' => 'The purchase date cannot be in the future.',
        ], [
            'purchasedOn' => 'date',
            'lines.*.quantity' => 'quantity',
            'lines.*.line_total' => 'amount',
        ]);

        try {
            $purchase = $purchases->record([
                'supplier_name' => $this->supplierName,
                'purchased_on' => $this->purchasedOn,
                'account_id' => $this->accountId ?: null,
                'note' => $this->note,
            ], $this->lines);
        } catch (PosException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        session()->flash('toast', ['type' => 'success', 'message' => $purchase->voucher_number.' saved. Stock updated.']);
        $this->redirectRoute('shop.purchases.index', navigate: true);
    }
};
?>

@php
    $shop = $this->shop;
    $total = $this->total();
@endphp

<div>
    <x-page-header title="New purchase" :breadcrumbs="['Purchases' => route('shop.purchases.index'), 'New' => null]" />

    @if ($this->ingredients->isEmpty())
        <div class="alert alert-info sfms-banner">
            Add your <a href="{{ route('shop.inventory.index') }}" wire:navigate class="alert-link">ingredients</a> first, then record what you buy.
        </div>
    @endif

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">Items bought</h5></div>
                    <div class="card-body vstack gap-3">
                        @foreach ($lines as $i => $line)
                            @php($ingredient = $this->ingredients->firstWhere('id', (int) $line['ingredient_id']))
                            @php($perUnit = $ingredient && is_numeric($line['quantity']) && (float) $line['quantity'] > 0 && is_numeric($line['line_total']) ? (float) $line['line_total'] / (float) $line['quantity'] : null)
                            <div class="border rounded-3 p-3" wire:key="pl-{{ $i }}">
                                <div class="row g-2">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label fs-12 mb-1">Ingredient</label>
                                        <select class="form-select form-select-touch" wire:model.live="lines.{{ $i }}.ingredient_id">
                                            <option value="">Choose…</option>
                                            @foreach ($this->ingredients as $option)
                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                        @if ($ingredient)
                                            <div class="fs-12 text-muted mt-1">In stock: {{ $ingredient->stockLabel() }}@if ((float) $ingredient->avg_cost > 0) &middot; avg {{ $shop->money($ingredient->avg_cost) }}/{{ $ingredient->unit }}@endif</div>
                                        @endif
                                    </div>
                                    <div class="col-4 col-md-2">
                                        <label class="form-label fs-12 mb-1">Quantity</label>
                                        <input type="text" inputmode="decimal" class="form-control form-control-touch @error('lines.'.$i.'.quantity') is-invalid @enderror" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity">
                                    </div>
                                    <div class="col-3 col-md-2">
                                        <label class="form-label fs-12 mb-1">Unit</label>
                                        <select class="form-select form-select-touch" wire:model.live="lines.{{ $i }}.unit" @disabled(! $ingredient)>
                                            @foreach ($ingredient ? \App\Support\Quantity::compatible($ingredient->unit) : [''] as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-5 col-md-3">
                                        <label class="form-label fs-12 mb-1">Amount paid</label>
                                        <div class="input-group">
                                            <input type="text" inputmode="decimal" class="form-control form-control-touch @error('lines.'.$i.'.line_total') is-invalid @enderror" wire:model.live.debounce.400ms="lines.{{ $i }}.line_total">
                                            <button type="button" class="btn btn-light text-danger" wire:click="removeLine({{ $i }})" aria-label="Remove line"><i class="ri-close-line"></i></button>
                                        </div>
                                    </div>
                                </div>
                                @if ($perUnit !== null)
                                    <div class="fs-12 text-muted mt-2">= {{ $shop->money($perUnit) }} per {{ $line['unit'] }}</div>
                                @endif
                            </div>
                        @endforeach

                        <div>
                            <button type="button" class="btn btn-light btn-sm" wire:click="addLine"><i class="ri-add-line"></i>Add item</button>
                        </div>

                        @error('lines')<div class="alert alert-danger py-2 mb-0">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">Details</h5></div>
                    <div class="card-body vstack gap-3">
                        <x-input model="supplierName" label="Supplier" placeholder="e.g. Al-Madina Poultry" />
                        <x-input model="purchasedOn" label="Date" type="date" required :max="$shop->today()->toDateString()" />
                        <div>
                            <label class="form-label" for="f-accountId">Paid from</label>
                            <select id="f-accountId" class="form-select form-select-touch" wire:model.live="accountId">
                                @foreach ($this->accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} ({{ $shop->money($account->current_balance) }})</option>
                                @endforeach
                                <option value="">Not paid yet (on credit)</option>
                            </select>
                            <div class="form-text">{{ $accountId === '' ? 'Stock goes up; no money leaves an account.' : 'The total is taken from this account.' }}</div>
                        </div>
                        <x-textarea model="note" label="Note" rows="2" />

                        <div class="hstack justify-content-between border-top pt-3">
                            <span class="text-muted">Total</span>
                            <span class="fs-4 fw-bold">{{ $shop->money($total) }}</span>
                        </div>

                        <div class="sfms-sticky-actions">
                            <button type="submit" class="btn btn-primary btn-touch w-100" wire:loading.attr="disabled" wire:target="save" @disabled($this->ingredients->isEmpty())>
                                <i class="ri-check-line"></i>Save purchase
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
