<?php

use App\Models\Category;
use App\Models\Product;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The POS menu grid. It is its own component so the (large) grid is rendered
 * once: cart changes re-render only the POS page around it. Filtering and the
 * size/add-on sheet run in Alpine (sfms-pos.js); choosing an item calls the
 * parent's addLine(), which prices it on the server.
 */
new class extends Component
{
    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function products()
    {
        return Product::query()
            ->where('is_available', true)
            ->whereHas('variants', fn ($q) => $q->where('is_available', true))
            ->with([
                'category',
                'variants' => fn ($q) => $q->where('is_available', true),
                'modifierGroups.options' => fn ($q) => $q->where('is_available', true),
                'comboItems.variant.product.variants',
            ])
            ->ordered()
            ->get();
    }

    #[Computed]
    public function categories()
    {
        $used = $this->products->pluck('category_id')->filter()->unique();

        return Category::query()->whereIn('id', $used)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Everything the browser needs to filter and configure items, keyed by product id. */
    #[Computed]
    public function menu(): array
    {
        return $this->products->mapWithKeys(function (Product $product) {
            $groups = $product->modifierGroups->filter(fn ($g) => $g->options->isNotEmpty() || $g->min_select > 0);

            return [$product->id => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category_id ?? 0,
                'search' => Str::lower($product->name.' '.$product->sku.' '.$product->category?->name),
                'simple' => $product->variants->count() === 1 && $groups->isEmpty(),
                'defaultVariant' => ($product->variants->firstWhere('is_default', true) ?? $product->variants->first())->id,
                'variants' => $product->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => (float) $v->price])->values(),
                'groups' => $groups->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'min' => $g->min_select,
                    'max' => $g->max_select,
                    'rule' => $g->ruleLabel(),
                    'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'price' => (float) $o->price])->values(),
                ])->values(),
                'combo' => $product->isCombo()
                    ? $product->comboItems->map(fn ($c) => $c->quantity.' × '.$c->variant->product->name.($c->variant->product->variants->count() > 1 ? ' ('.$c->variant->name.')' : ''))->values()
                    : [],
                'description' => $product->description,
            ]];
        })->all();
    }
};
?>

<div class="sfms-pos-menu" x-data="sfmsPosMenu(@js($this->menu), @js($this->shop->currency_symbol))">
    <div class="sfms-pos-toolbar">
        <div class="input-group">
            <span class="input-group-text bg-body text-muted"><i class="ri-search-line"></i></span>
            <input type="search" class="form-control form-control-touch border-start-0 ps-0" placeholder="Search the menu…" x-model="q"
                   x-on:keydown.escape="q = ''" aria-label="Search the menu" autocomplete="off">
        </div>

        <div class="sfms-filter-tabs mt-2">
            <button type="button" class="btn btn-sm" x-bind:class="cat === 'all' && q === '' ? 'btn-primary' : 'btn-light'" x-on:click="setCategory('all')">All</button>
            @foreach ($this->categories as $category)
                <button type="button" class="btn btn-sm" wire:key="cat-{{ $category->id }}"
                        x-bind:class="cat === {{ $category->id }} && q === '' ? 'btn-primary' : 'btn-light'"
                        x-on:click="setCategory({{ $category->id }})">{{ $category->name }}</button>
            @endforeach
            @if ($this->products->contains(fn ($p) => ! $p->category_id))
                <button type="button" class="btn btn-sm" x-bind:class="cat === 0 && q === '' ? 'btn-primary' : 'btn-light'" x-on:click="setCategory(0)">Other</button>
            @endif
        </div>
    </div>

    @if ($this->products->isEmpty())
        <div class="card mb-0 mt-3">
            <x-empty-state icon="ri-restaurant-line" title="No items to sell yet">
                Add products on the Products page and they will show up here.
            </x-empty-state>
        </div>
    @else
        <div class="sfms-pos-menu-scroll mt-3">
        <div class="sfms-pos-grid">
            @foreach ($this->products as $product)
                @php($data = $this->menu[$product->id])
                <button type="button" class="sfms-pos-item" wire:key="p-{{ $product->id }}"
                        x-show="visible({{ $product->id }}, {{ $product->category_id ?? 0 }})"
                        x-on:click="pick({{ $product->id }}, $el)">
                    <span class="sfms-pos-item-thumb">
                        @if ($product->imageUrl())
                            <img src="{{ $product->imageUrl() }}" alt="" loading="lazy">
                        @else
                            {{ $product->initials() }}
                        @endif
                        @if (! $data['simple'])
                            <span class="sfms-pos-item-more" title="Has sizes or add-ons"><i class="ri-add-line"></i></span>
                        @endif
                    </span>
                    <span class="sfms-pos-item-name">{{ $product->name }}</span>
                    <span class="sfms-pos-item-price">{{ $product->priceLabel($this->shop) }}</span>
                </button>
            @endforeach
        </div>

        <div class="text-center text-muted py-5" x-cloak x-show="visibleCount === 0">
            <i class="ri-search-eye-line fs-24 d-block mb-2"></i>
            No items match "<span x-text="q"></span>".
        </div>
        </div>
    @endif

    {{-- Size / add-on sheet --}}
    <div class="modal fade" tabindex="-1" aria-hidden="true" x-ref="sheet" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <template x-if="item">
                    <div class="sfms-sheet-inner">
                        <div class="modal-header">
                            <div class="min-w-0">
                                <h5 class="modal-title text-truncate" x-text="item.name"></h5>
                                <div class="fs-12 text-muted" x-show="item.combo.length" x-text="item.combo.join(' · ')"></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body vstack gap-4">
                            <template x-if="item.variants.length > 1">
                                <div>
                                    <div class="fw-semibold mb-2">Size</div>
                                    <div class="sfms-choice-grid">
                                        <template x-for="v in item.variants" :key="v.id">
                                            <button type="button" class="sfms-choice" :class="{ 'is-active': variantId === v.id }" x-on:click="variantId = v.id">
                                                <span x-text="v.name"></span>
                                                <span class="sfms-choice-price" x-text="money(v.price)"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-for="group in item.groups" :key="group.id">
                                <div>
                                    <div class="hstack justify-content-between mb-2">
                                        <span class="fw-semibold" x-text="group.name"></span>
                                        <span class="badge" :class="group.min > 0 ? 'bg-danger-subtle text-danger' : 'bg-light text-muted'" x-text="group.rule"></span>
                                    </div>
                                    <div class="sfms-choice-grid">
                                        <template x-for="option in group.options" :key="option.id">
                                            <button type="button" class="sfms-choice" :class="{ 'is-active': isChosen(group, option) }" x-on:click="toggle(group, option)">
                                                <span>
                                                    <i :class="group.max === 1 ? (isChosen(group, option) ? 'ri-radio-button-line' : 'ri-checkbox-blank-circle-line') : (isChosen(group, option) ? 'ri-checkbox-line' : 'ri-checkbox-blank-line')" class="me-1"></i>
                                                    <span x-text="option.name"></span>
                                                </span>
                                                <span class="sfms-choice-price" x-show="option.price > 0" x-text="'+ ' + money(option.price)"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <div>
                                <label class="form-label fw-semibold" for="pos-sheet-note">Note for the kitchen</label>
                                <input id="pos-sheet-note" type="text" class="form-control form-control-touch" maxlength="255" x-model="note" placeholder="e.g. no onions, extra spicy">
                            </div>

                            <div class="alert alert-danger py-2 mb-0" x-show="error" x-text="error"></div>
                        </div>

                        <div class="modal-footer flex-nowrap">
                            <div class="sfms-qty">
                                <button type="button" class="btn btn-light" x-on:click="changeQty(-1)" aria-label="Less"><i class="ri-subtract-line"></i></button>
                                <span class="sfms-qty-value" x-text="qty"></span>
                                <button type="button" class="btn btn-light" x-on:click="changeQty(1)" aria-label="More"><i class="ri-add-line"></i></button>
                            </div>
                            <button type="button" class="btn btn-primary btn-touch flex-grow-1" x-on:click="confirm()">
                                Add &middot; <span x-text="money(unitPrice * qty)"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
