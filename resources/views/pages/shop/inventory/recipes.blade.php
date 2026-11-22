<?php

use App\Exceptions\PosException;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Recipes;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Recipes')] class extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $missingOnly = false;

    public bool $showEditor = false;
    #[Locked]
    public string $editType = 'variant';
    #[Locked]
    public ?int $editId = null;
    public array $rows = [];

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
    public function products()
    {
        return Product::query()
            ->with([
                'category',
                'variants.recipeItems.ingredient',
                'comboItems.variant.product',
                'comboItems.variant.recipeItems.ingredient',
            ])
            ->ordered()
            ->get();
    }

    #[Computed]
    public function groups()
    {
        return ModifierGroup::query()->with('options.recipeItems.ingredient')->ordered()->get();
    }

    /** Ingredient cost of one unit, including a deal's items. */
    public function variantCost(Product $product, ProductVariant $variant): string
    {
        $cost = Recipes::cost($variant->recipeItems);

        if ($product->isCombo()) {
            foreach ($product->comboItems as $component) {
                $cost = Money::add($cost, Money::mul(Recipes::cost($component->variant->recipeItems), $component->quantity));
            }
        }

        return $cost;
    }

    public function hasRecipe(Product $product, ProductVariant $variant): bool
    {
        return $variant->recipeItems->isNotEmpty()
            || ($product->isCombo() && $product->comboItems->contains(fn ($c) => $c->variant->recipeItems->isNotEmpty()));
    }

    #[Computed]
    public function coverage(): array
    {
        $variants = $this->products->flatMap(fn ($p) => $p->variants->map(fn ($v) => [$p, $v]));

        return [
            'total' => $variants->count(),
            'done' => $variants->filter(fn ($pair) => $this->hasRecipe(...$pair))->count(),
        ];
    }

    #[Computed]
    public function editing(): ProductVariant|ModifierOption|null
    {
        if (! $this->editId) {
            return null;
        }

        return $this->editType === 'option'
            ? ModifierOption::query()->with('group')->find($this->editId)
            : ProductVariant::query()->with('product.variants.recipeItems')->find($this->editId);
    }

    public function edit(string $type, int $id): void
    {
        $this->editType = $type === 'option' ? 'option' : 'variant';
        $this->editId = $id;
        unset($this->editing);

        $recipeable = $this->editing ?? abort(404);

        $this->rows = $recipeable->recipeItems()->get()->map(fn ($r) => [
            'ingredient_id' => (string) $r->ingredient_id,
            'quantity' => Quantity::plain($r->quantity),
            'unit' => $r->unit,
        ])->all();

        if ($this->rows === []) {
            $this->addRow();
        }

        $this->resetValidation();
        $this->showEditor = true;
    }

    public function addRow(): void
    {
        $this->rows[] = ['ingredient_id' => '', 'quantity' => '', 'unit' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    /** Copies another size's recipe of the same product into the editor. */
    public function copyFrom(int $variantId): void
    {
        $source = ProductVariant::query()->with('recipeItems')->find($variantId);

        if (! $source || $this->editType !== 'variant' || $source->product_id !== $this->editing?->product_id) {
            return;
        }

        $this->rows = $source->recipeItems->map(fn ($r) => [
            'ingredient_id' => (string) $r->ingredient_id,
            'quantity' => Quantity::plain($r->quantity),
            'unit' => $r->unit,
        ])->all();
    }

    /** Choosing an ingredient picks its own unit. */
    public function updatedRows(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if ($field === 'ingredient_id' && isset($this->rows[$index])) {
            $ingredient = $this->ingredients->firstWhere('id', (int) $value);
            $this->rows[$index]['unit'] = $ingredient?->unit ?? '';
        }
    }

    public function rowCost(array $row): ?string
    {
        $ingredient = $this->ingredients->firstWhere('id', (int) ($row['ingredient_id'] ?? 0));

        if (! $ingredient || ! is_numeric($row['quantity'] ?? null) || ! in_array($row['unit'] ?? '', Quantity::compatible($ingredient->unit), true)) {
            return null;
        }

        return Money::of(bcmul(Quantity::convert($row['quantity'], $row['unit'], $ingredient->unit), Quantity::of($ingredient->avg_cost), 4));
    }

    public function save(Recipes $recipes): void
    {
        $recipeable = $this->editing;

        if (! $recipeable) {
            $this->showEditor = false;

            return;
        }

        try {
            $recipes->save($recipeable, $this->rows);
        } catch (PosException $e) {
            $this->addError('rows', $e->getMessage());

            return;
        }

        $this->showEditor = false;
        unset($this->products, $this->groups, $this->coverage);
        $this->dispatch('toast', type: 'success', message: 'Recipe saved.');
    }
};
?>

@php
    $shop = $this->shop;
    $term = mb_strtolower(trim($search));
    $matches = fn (string $text) => $term === '' || str_contains(mb_strtolower($text), $term);
    $coverage = $this->coverage;
    $pct = fn ($cost, $price) => (float) $price > 0 ? round((float) $cost / (float) $price * 100) : null;
@endphp

<div>
    <x-page-header title="Recipes" subtitle="What one of each menu item uses. Stock is taken when the item is sent to the kitchen.">
        <x-slot:actions>
            <a href="{{ route('shop.inventory.index') }}" wire:navigate class="btn btn-light btn-touch"><i class="ri-archive-stack-line"></i>Ingredients</a>
        </x-slot:actions>
    </x-page-header>

    @if ($this->ingredients->isEmpty())
        <div class="alert alert-info hstack gap-2 sfms-banner">
            <i class="ri-information-line fs-18"></i>
            <span>Add your <a href="{{ route('shop.inventory.index') }}" wire:navigate class="alert-link">ingredients</a> first, then come back to give each item a recipe.</span>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-body text-muted"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control form-control-touch border-start-0 ps-0" placeholder="Search menu items and add-ons" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="missing-only" wire:model.live="missingOnly">
                        <label class="form-check-label" for="missing-only">Only items without a recipe</label>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <span class="fw-semibold">{{ $coverage['done'] }} of {{ $coverage['total'] }}</span> <span class="text-muted">menu sizes have recipes</span>
                    <div class="progress mt-1" style="height: .4rem">
                        <div class="progress-bar bg-success" style="width: {{ $coverage['total'] ? round($coverage['done'] / $coverage['total'] * 100) : 0 }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @foreach ($this->products->groupBy(fn ($p) => $p->category?->name ?? 'Other') as $category => $products)
        @php
            $variantRows = $products->flatMap(fn ($p) => $p->variants->map(fn ($v) => [$p, $v]))
                ->filter(fn ($pair) => $matches($pair[0]->name.' '.$pair[1]->name))
                ->filter(fn ($pair) => ! $missingOnly || ! $this->hasRecipe(...$pair));
        @endphp
        @continue($variantRows->isEmpty())

        <div class="card mb-3" wire:key="cat-{{ \Illuminate\Support\Str::slug($category) }}">
            <div class="card-header"><h5 class="card-title mb-0">{{ $category }}</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light"><tr><th>Item</th><th>Recipe</th><th class="text-end">Cost</th><th class="text-end">Food cost</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($variantRows as [$product, $variant])
                                @php
                                    $cost = $this->variantCost($product, $variant);
                                    $percent = $pct($cost, $variant->price);
                                @endphp
                                <tr wire:key="v-{{ $variant->id }}">
                                    <td class="td-primary">
                                        <div class="fw-semibold">{{ $product->name }}@if ($product->variants->count() > 1) <span class="text-muted fw-normal">· {{ $variant->name }}</span>@endif</div>
                                        <div class="fs-12 text-muted">{{ $shop->money($variant->price) }}@if ($product->isCombo()) &middot; deal @endif</div>
                                    </td>
                                    <td data-label="Recipe">
                                        <div class="cell-stack fs-13">
                                            @if ($variant->recipeItems->isNotEmpty())
                                                {{ $variant->recipeItems->map(fn ($r) => $r->ingredient?->name.' '.$r->label())->implode(' · ') }}
                                            @elseif (! $product->isCombo())
                                                <span class="badge bg-warning-subtle text-warning">No recipe</span>
                                            @endif
                                            @if ($product->isCombo())
                                                <div class="text-muted fs-12">+ its items: {{ $product->comboItems->map(fn ($c) => $c->quantity.'× '.$c->variant->product->name)->implode(', ') }}</div>
                                            @endif
                                        </div>
                                    </td>
                                    <td data-label="Cost" class="text-end">{{ (float) $cost > 0 ? $shop->money($cost) : '—' }}</td>
                                    <td data-label="Food cost" class="text-end">
                                        @if ($percent !== null && (float) $cost > 0)
                                            <span class="badge {{ $percent > 45 ? 'bg-danger-subtle text-danger' : ($percent > 35 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">{{ $percent }}%</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="td-actions text-end">
                                        <button type="button" class="btn btn-sm btn-light" wire:click="edit('variant', {{ $variant->id }})" @disabled($this->ingredients->isEmpty())>
                                            <i class="ri-edit-line"></i>{{ $variant->recipeItems->isEmpty() ? 'Add recipe' : 'Edit' }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

    {{-- Add-ons --}}
    @php
        $optionRows = $this->groups->flatMap(fn ($g) => $g->options->map(fn ($o) => [$g, $o]))
            ->filter(fn ($pair) => $matches($pair[0]->name.' '.$pair[1]->name))
            ->filter(fn ($pair) => ! $missingOnly || $pair[1]->recipeItems->isEmpty());
    @endphp
    @if ($optionRows->isNotEmpty())
        <div class="card mb-0">
            <div class="card-header">
                <h5 class="card-title mb-0">Add-ons</h5>
                <p class="text-muted fs-13 mb-0">Only add-ons that use stock need a recipe (extra cheese yes, "no onions" no).</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light"><tr><th>Add-on</th><th>Recipe</th><th class="text-end">Cost</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($optionRows as [$group, $option])
                                @php $cost = \App\Services\Recipes::cost($option->recipeItems); @endphp
                                <tr wire:key="o-{{ $option->id }}">
                                    <td class="td-primary">
                                        <div class="fw-semibold">{{ $option->name }}</div>
                                        <div class="fs-12 text-muted">{{ $group->name }}@if ((float) $option->price > 0) &middot; +{{ $shop->money($option->price) }}@endif</div>
                                    </td>
                                    <td data-label="Recipe" class="fs-13">
                                        @if ($option->recipeItems->isNotEmpty())
                                            <span class="cell-stack">{{ $option->recipeItems->map(fn ($r) => $r->ingredient?->name.' '.$r->label())->implode(' · ') }}</span>
                                        @else
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td data-label="Cost" class="text-end">{{ (float) $cost > 0 ? $shop->money($cost) : '—' }}</td>
                                    <td class="td-actions text-end">
                                        <button type="button" class="btn btn-sm btn-light" wire:click="edit('option', {{ $option->id }})" @disabled($this->ingredients->isEmpty())>
                                            <i class="ri-edit-line"></i>{{ $option->recipeItems->isEmpty() ? 'Add recipe' : 'Edit' }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Editor --}}
    @php $recipeable = $showEditor ? $this->editing : null; @endphp
    <x-modal wire:model="showEditor" size="lg"
             :title="$recipeable instanceof \App\Models\ModifierOption
                ? 'Recipe · '.$recipeable->name.' ('.$recipeable->group?->name.')'
                : ($recipeable ? 'Recipe · '.$recipeable->product->name.($recipeable->product->variants->count() > 1 ? ' ('.$recipeable->name.')' : '') : 'Recipe')">
        @if ($recipeable)
            @php
                $price = (float) $recipeable->price;
                $costs = collect($rows)->map(fn ($row) => $this->rowCost($row));
                $total = \App\Support\Money::sum($costs->filter());
                $siblings = $recipeable instanceof \App\Models\ProductVariant
                    ? $recipeable->product->variants->where('id', '!=', $recipeable->id)->filter(fn ($v) => $v->recipeItems->isNotEmpty())
                    : collect();
            @endphp
            <form wire:submit="save" novalidate>
                <div class="modal-body vstack gap-3">
                    <p class="text-muted fs-13 mb-0">
                        Quantities are for <strong>one</strong> {{ $recipeable instanceof \App\Models\ModifierOption ? 'add-on' : 'serving' }}.
                        You can enter a smaller unit, e.g. 200 g of an ingredient counted in kg.
                    </p>

                    @if ($siblings->isNotEmpty())
                        <div class="hstack flex-wrap gap-1">
                            <span class="fs-13 text-muted me-1">Copy from:</span>
                            @foreach ($siblings as $sibling)
                                <button type="button" class="btn btn-sm btn-light" wire:click="copyFrom({{ $sibling->id }})">{{ $sibling->name }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="vstack gap-2">
                        @foreach ($rows as $i => $row)
                            @php $ingredient = $this->ingredients->firstWhere('id', (int) $row['ingredient_id']); @endphp
                            <div class="row g-2 align-items-center" wire:key="row-{{ $i }}">
                                <div class="col-12 col-sm-6">
                                    <select class="form-select form-select-touch" wire:model.live="rows.{{ $i }}.ingredient_id" aria-label="Ingredient">
                                        <option value="">Choose ingredient…</option>
                                        @foreach ($this->ingredients as $option)
                                            @if ($option->is_active || (int) $row['ingredient_id'] === $option->id)
                                                <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->unit }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-5 col-sm-2">
                                    <input type="text" inputmode="decimal" class="form-control form-control-touch" placeholder="Qty" wire:model.live.debounce.400ms="rows.{{ $i }}.quantity" aria-label="Quantity">
                                </div>
                                <div class="col-4 col-sm-2">
                                    <select class="form-select form-select-touch" wire:model.live="rows.{{ $i }}.unit" aria-label="Unit" @disabled(! $ingredient)>
                                        @foreach ($ingredient ? \App\Support\Quantity::compatible($ingredient->unit) : ['' => ''] as $unit)
                                            <option value="{{ $unit }}">{{ $unit }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-3 col-sm-2 hstack gap-1 justify-content-end">
                                    <span class="fs-12 text-muted text-nowrap">{{ $costs[$i] !== null ? $shop->money($costs[$i]) : '' }}</span>
                                    <button type="button" class="btn btn-sm btn-light text-danger" wire:click="removeRow({{ $i }})" aria-label="Remove"><i class="ri-close-line"></i></button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <button type="button" class="btn btn-sm btn-light" wire:click="addRow"><i class="ri-add-line"></i>Add ingredient</button>
                    </div>

                    @error('rows')<div class="alert alert-danger py-2 mb-0">{{ $message }}</div>@enderror

                    <div class="hstack justify-content-between bg-light rounded-3 px-3 py-2">
                        <span class="text-muted">Ingredient cost</span>
                        <span>
                            <span class="fw-semibold">{{ $shop->money($total) }}</span>
                            @if ($price > 0 && (float) $total > 0)
                                <span class="text-muted">· {{ round((float) $total / $price * 100) }}% of {{ $shop->money($price) }}</span>
                            @endif
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save recipe</button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
