<?php

use App\Exceptions\PosException;
use App\Models\Ingredient;
use App\Services\Stock;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Ingredients')] class extends Component
{
    #[Url(except: '')]
    public string $filter = '';

    #[Url(except: '')]
    public string $search = '';

    // Add / edit
    public bool $showForm = false;
    #[Locked]
    public ?int $editingId = null;
    public string $name = '';
    public string $unit = 'kg';
    public string $lowStockThreshold = '';
    public string $openingStock = '';
    public string $openingCost = '';

    // Count / wastage
    public bool $showAdjust = false;
    #[Locked]
    public ?int $adjustId = null;
    public string $adjustMode = 'count';
    public string $adjustQuantity = '';
    public string $adjustNote = '';

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function all()
    {
        return Ingredient::query()->withCount(['recipeItems', 'movements'])->orderBy('name')->get();
    }

    #[Computed]
    public function ingredients()
    {
        $term = mb_strtolower(trim($this->search));

        return $this->all
            ->filter(fn (Ingredient $i) => match ($this->filter) {
                'low' => $i->is_active && $i->stockStatus() === 'low',
                'out' => $i->is_active && $i->stockStatus() === 'out',
                'inactive' => ! $i->is_active,
                default => $i->is_active,
            })
            ->filter(fn (Ingredient $i) => $term === '' || str_contains(mb_strtolower($i->name), $term))
            ->values();
    }

    #[Computed]
    public function stats(): array
    {
        $active = $this->all->where('is_active', true);

        return [
            'count' => $active->count(),
            'low' => $active->filter(fn ($i) => $i->stockStatus() === 'low')->count(),
            'out' => $active->filter(fn ($i) => $i->stockStatus() === 'out')->count(),
            'value' => Money::sum($active->map(fn ($i) => $i->stockValue())),
            'inactive' => $this->all->where('is_active', false)->count(),
        ];
    }

    #[Computed]
    public function editing(): ?Ingredient
    {
        return $this->editingId ? $this->all->firstWhere('id', $this->editingId) : null;
    }

    #[Computed]
    public function adjusting(): ?Ingredient
    {
        return $this->adjustId ? $this->all->firstWhere('id', $this->adjustId) : null;
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'lowStockThreshold', 'openingStock', 'openingCost');
        $this->unit = 'kg';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $ingredient = Ingredient::query()->findOrFail($id);

        $this->editingId = $ingredient->id;
        $this->name = $ingredient->name;
        $this->unit = $ingredient->unit;
        $this->lowStockThreshold = $ingredient->low_stock_threshold === null ? '' : Quantity::plain($ingredient->low_stock_threshold);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(Stock $stock): void
    {
        $editing = $this->editingId ? Ingredient::query()->withCount('movements')->findOrFail($this->editingId) : null;
        $unitLocked = $editing && ($editing->movements_count > 0 || $editing->recipeItems()->exists());

        $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('ingredients', 'name')->where('tenant_id', $this->shop->id)->ignore($editing?->id)],
            'unit' => ['required', Rule::in(array_keys(Quantity::UNITS))],
            'lowStockThreshold' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'openingStock' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'openingCost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ], [
            'name.unique' => 'You already have an ingredient with this name.',
        ], [
            'lowStockThreshold' => 'low-stock level',
            'openingStock' => 'stock on hand',
            'openingCost' => 'cost per unit',
        ]);

        if ($unitLocked && $this->unit !== $editing->unit) {
            $this->addError('unit', 'The unit can\'t change once the ingredient has stock movements or is in a recipe.');

            return;
        }

        $attributes = [
            'name' => trim($this->name),
            'unit' => $this->unit,
            'low_stock_threshold' => $this->lowStockThreshold === '' ? null : Quantity::of($this->lowStockThreshold),
        ];

        if ($editing) {
            $editing->update($attributes);
            $message = $editing->name.' updated.';
        } else {
            $ingredient = Ingredient::create($attributes);
            $stock->opening($ingredient, $this->openingStock ?: 0, $this->openingCost ?: 0);
            $message = $ingredient->name.' added.';
        }

        $this->showForm = false;
        unset($this->all, $this->ingredients, $this->stats);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function openAdjust(int $id, string $mode): void
    {
        $ingredient = Ingredient::query()->findOrFail($id);

        $this->adjustId = $ingredient->id;
        $this->adjustMode = $mode === 'waste' ? 'waste' : 'count';
        $this->adjustQuantity = $this->adjustMode === 'count' ? Quantity::plain($ingredient->current_stock) : '';
        $this->adjustNote = '';
        $this->resetValidation();
        $this->showAdjust = true;
    }

    public function saveAdjust(Stock $stock): void
    {
        $ingredient = Ingredient::query()->findOrFail($this->adjustId);

        $this->validate([
            'adjustMode' => ['required', 'in:count,waste'],
            'adjustQuantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'adjustNote' => ['required', 'string', 'min:3', 'max:255'],
        ], [], [
            'adjustQuantity' => $this->adjustMode === 'count' ? 'counted stock' : 'quantity wasted',
            'adjustNote' => $this->adjustMode === 'count' ? 'reason' : 'what happened',
        ]);

        try {
            $movement = $this->adjustMode === 'count'
                ? $stock->count($ingredient, $this->adjustQuantity, $this->adjustNote)
                : $stock->waste($ingredient, $this->adjustQuantity, $this->adjustNote);
        } catch (PosException $e) {
            $this->addError('adjustQuantity', $e->getMessage());

            return;
        }

        $this->showAdjust = false;
        unset($this->all, $this->ingredients, $this->stats);
        $this->dispatch('toast', type: 'success', message: $ingredient->name.': '.($movement->quantity < 0 ? '' : '+').Quantity::format($movement->quantity, $ingredient->unit).', now '.Quantity::format($movement->balance_after, $ingredient->unit).'.');
    }

    public function toggleActive(int $id): void
    {
        $ingredient = Ingredient::query()->findOrFail($id);
        $ingredient->update(['is_active' => ! $ingredient->is_active]);

        unset($this->all, $this->ingredients, $this->stats);
        $this->dispatch('toast', type: 'success', message: $ingredient->name.($ingredient->is_active ? ' is active again.' : ' is hidden from lists. Recipes still use it.'));
    }

    public function delete(int $id): void
    {
        $ingredient = Ingredient::query()->withCount(['movements', 'recipeItems'])->findOrFail($id);

        if ($ingredient->movements_count > 0 || $ingredient->recipe_items_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'Ingredients with stock history or in recipes can\'t be deleted. Deactivate it instead.');

            return;
        }

        $ingredient->delete();
        unset($this->all, $this->ingredients, $this->stats);
        $this->dispatch('toast', type: 'success', message: 'Ingredient deleted.');
    }
};
?>

@php
    $shop = $this->shop;
    $stats = $this->stats;
    $units = collect(\App\Support\Quantity::UNITS)->mapWithKeys(fn ($u, $key) => [$key => $u[2].' ('.$key.')'])->all();
@endphp

<div>
    <x-page-header title="Ingredients" subtitle="What the kitchen uses up. Stock goes down when items are sent to the kitchen and up with purchases.">
        <x-slot:actions>
            @can('purchases.manage')
                <a href="{{ route('shop.purchases.create') }}" wire:navigate class="btn btn-light btn-touch"><i class="ri-truck-line"></i>New purchase</a>
            @endcan
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-add-line"></i>Add ingredient</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-kpi label="Ingredients" :value="$stats['count']" icon="ri-archive-stack-line" tone="primary" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Low stock" :value="$stats['low']" icon="ri-error-warning-line" tone="warning" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Out of stock" :value="$stats['out']" icon="ri-close-circle-line" tone="danger" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Stock value" :value="$shop->money($stats['value'])" icon="ri-money-dollar-circle-line" tone="success" hint="At average cost" /></div>
    </div>

    <div class="card mb-0">
        <div class="card-header">
            <div class="row g-2 align-items-center">
                <div class="col-md">
                    <div class="sfms-filter-tabs">
                        @foreach (['' => ['Active', $stats['count']], 'low' => ['Low', $stats['low']], 'out' => ['Out', $stats['out']], 'inactive' => ['Inactive', $stats['inactive']]] as $value => [$label, $n])
                            <button type="button" class="btn btn-sm {{ $filter === $value ? 'btn-primary' : 'btn-light' }}" wire:click="$set('filter', '{{ $value }}')">
                                {{ $label }} <span class="badge {{ $filter === $value ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary' }} ms-1">{{ $n }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-body text-muted"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control form-control-touch border-start-0 ps-0" placeholder="Search ingredients" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            @if ($this->ingredients->isEmpty())
                <x-empty-state icon="ri-archive-stack-line" :title="$this->all->isEmpty() ? 'No ingredients yet' : 'Nothing here'">
                    @if ($this->all->isEmpty())
                        Add what you buy and cook with (chicken, buns, oil…), then give your menu items recipes.
                    @else
                        No ingredients match this filter.
                    @endif
                </x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Ingredient</th><th class="text-end">In stock</th><th class="text-end">Low at</th><th class="text-end">Avg cost</th><th class="text-end">Value</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->ingredients as $ingredient)
                                @php($badge = $ingredient->stockBadge())
                                <tr wire:key="ing-{{ $ingredient->id }}">
                                    <td class="td-primary">
                                        <div class="fw-semibold">{{ $ingredient->name }}</div>
                                        <div class="fs-12 text-muted">{{ $ingredient->unitLabel() }} &middot; in {{ $ingredient->recipe_items_count }} {{ \Illuminate\Support\Str::plural('recipe', $ingredient->recipe_items_count) }}</div>
                                    </td>
                                    <td data-label="In stock" class="text-end fw-semibold {{ $ingredient->stockStatus() === 'out' ? 'text-danger' : ($ingredient->stockStatus() === 'low' ? 'text-warning' : '') }}">{{ $ingredient->stockLabel() }}</td>
                                    <td data-label="Low at" class="text-end text-muted">{{ $ingredient->low_stock_threshold === null ? '—' : \App\Support\Quantity::format($ingredient->low_stock_threshold, $ingredient->unit) }}</td>
                                    <td data-label="Avg cost" class="text-end">{{ (float) $ingredient->avg_cost > 0 ? $shop->money($ingredient->avg_cost).' / '.$ingredient->unit : '—' }}</td>
                                    <td data-label="Value" class="text-end">{{ $shop->money($ingredient->stockValue()) }}</td>
                                    <td data-label="Status"><span class="badge {{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                                    <td class="td-actions text-end">
                                        <div class="hstack gap-1 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light" wire:click="openAdjust({{ $ingredient->id }}, 'count')">Count</button>
                                            <div class="dropdown">
                                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="More"><i class="ri-more-2-fill"></i></button>
                                                <div class="dropdown-menu dropdown-menu-end">
                                                    <button type="button" class="dropdown-item" wire:click="openEdit({{ $ingredient->id }})"><i class="ri-pencil-line me-2"></i>Edit</button>
                                                    <button type="button" class="dropdown-item" wire:click="openAdjust({{ $ingredient->id }}, 'waste')"><i class="ri-delete-bin-6-line me-2"></i>Record wastage</button>
                                                    <a class="dropdown-item" href="{{ route('shop.stock.ledger', ['ingredient' => $ingredient->id]) }}" wire:navigate><i class="ri-git-commit-line me-2"></i>Stock history</a>
                                                    <button type="button" class="dropdown-item" wire:click="toggleActive({{ $ingredient->id }})">
                                                        <i class="{{ $ingredient->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }} me-2"></i>{{ $ingredient->is_active ? 'Deactivate' : 'Activate' }}
                                                    </button>
                                                    @if ($ingredient->movements_count === 0 && $ingredient->recipe_items_count === 0)
                                                        <button type="button" class="dropdown-item text-danger" wire:click="delete({{ $ingredient->id }})" wire:confirm="Delete {{ $ingredient->name }}?"><i class="ri-delete-bin-line me-2"></i>Delete</button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit ingredient' : 'Add ingredient'">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                <x-input model="name" label="Name" required placeholder="e.g. Chicken fillet, Burger bun" />
                @php($unitLocked = $this->editing && ($this->editing->movements_count > 0 || $this->editing->recipe_items_count > 0))
                <x-select model="unit" modifier="live" label="Counted in" :options="$units" :disabled="$unitLocked"
                          :hint="$unitLocked ? 'Locked: this ingredient already has stock history or recipes.' : 'Recipes and purchases can use any unit of the same kind (g for kg, ml for L, pcs/dozen).'" />
                <x-input model="lowStockThreshold" label="Warn when stock is at or below" inputmode="decimal" :append="$unit" placeholder="Optional" />
                @unless ($editingId)
                    <div class="row g-3">
                        <div class="col-6"><x-input model="openingStock" label="Stock on hand now" inputmode="decimal" :append="$unit" placeholder="0" /></div>
                        <div class="col-6"><x-input model="openingCost" label="Cost per {{ $unit }}" inputmode="decimal" :prepend="$shop->currency_symbol" placeholder="0" /></div>
                    </div>
                @endunless
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save</button>
            </div>
        </form>
    </x-modal>

    <x-modal wire:model="showAdjust" :title="$adjustMode === 'count' ? 'Count stock' : 'Record wastage'">
        <form wire:submit="saveAdjust" novalidate>
            <div class="modal-body vstack gap-3">
                @if ($this->adjusting)
                    <div class="hstack justify-content-between bg-light rounded-3 px-3 py-2">
                        <span class="fw-semibold">{{ $this->adjusting->name }}</span>
                        <span class="text-muted">Stock now: <span class="fw-semibold text-body">{{ $this->adjusting->stockLabel() }}</span></span>
                    </div>
                    <div class="sfms-type-switch" style="grid-template-columns: repeat(2, 1fr)">
                        <button type="button" class="btn btn-sm {{ $adjustMode === 'count' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('adjustMode', 'count')"><i class="ri-scales-3-line"></i>Counted</button>
                        <button type="button" class="btn btn-sm {{ $adjustMode === 'waste' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('adjustMode', 'waste')"><i class="ri-delete-bin-6-line"></i>Wasted</button>
                    </div>
                    <x-input model="adjustQuantity" :label="$adjustMode === 'count' ? 'Actual stock counted' : 'Quantity wasted'" inputmode="decimal" :append="$this->adjusting->unit" required />
                    <x-input model="adjustNote" :label="$adjustMode === 'count' ? 'Reason' : 'What happened'" required :placeholder="$adjustMode === 'count' ? 'e.g. Weekly stock count' : 'e.g. Expired, dropped, burnt'" />
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="saveAdjust">Save</button>
            </div>
        </form>
    </x-modal>
</div>
