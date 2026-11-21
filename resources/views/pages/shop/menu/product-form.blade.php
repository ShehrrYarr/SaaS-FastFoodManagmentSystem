<?php

use App\Models\Category;
use App\Models\ComboItem;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSequence;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::shop')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $productId = null;

    public string $name = '';
    public string $categoryId = '';
    public string $sku = '';
    public string $type = 'simple';
    public string $description = '';
    public bool $isAvailable = true;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $image = null;
    public bool $removeImage = false;

    /** @var list<array{id: ?int, name: string, price: string, is_available: bool}> */
    public array $variants = [];
    public int $defaultVariant = 0;

    /** @var list<int|string> */
    public array $modifierGroupIds = [];

    /** @var list<array{variant_id: string, quantity: string}> */
    public array $comboItems = [];

    public bool $addingCategory = false;
    public string $newCategory = '';

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $product->load(['variants', 'modifierGroups', 'comboItems']);

            $this->productId = $product->id;
            $this->name = $product->name;
            $this->categoryId = (string) $product->category_id;
            $this->sku = $product->sku;
            $this->type = $product->type;
            $this->description = (string) $product->description;
            $this->isAvailable = $product->is_available;
            $this->variants = $product->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'price' => $this->plain($v->price),
                'is_available' => $v->is_available,
            ])->all();
            $this->defaultVariant = max(0, (int) $product->variants->search(fn ($v) => $v->is_default));
            $this->modifierGroupIds = $product->modifierGroups->pluck('id')->map(fn ($id) => (string) $id)->all();
            $this->comboItems = $product->comboItems->map(fn (ComboItem $c) => [
                'variant_id' => (string) $c->product_variant_id,
                'quantity' => (string) $c->quantity,
            ])->all();
        }

        if ($this->variants === []) {
            $this->variants = [['id' => null, 'name' => 'Regular', 'price' => '', 'is_available' => true]];
            $this->categoryId = ctype_digit((string) request()->query('category')) ? (string) request()->query('category') : '';
        }
    }

    public function render()
    {
        return $this->view()->title($this->productId ? 'Edit product' : 'New product');
    }

    // ---------------------------------------------------------------- data

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function categories()
    {
        return Category::query()->ordered()->get();
    }

    #[Computed]
    public function modifierGroups()
    {
        return ModifierGroup::query()->ordered()->with('options')->get();
    }

    /** Variants of regular (non-combo) products, for building deals. */
    #[Computed]
    public function comboChoices()
    {
        return ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('type', 'simple'))
            ->with(['product' => fn ($q) => $q->withCount('variants')])
            ->get()
            ->sortBy(fn (ProductVariant $v) => $v->product->name.' '.$v->sort_order)
            ->mapWithKeys(fn (ProductVariant $v) => [
                $v->id => [
                    'label' => $v->product->variants_count > 1 ? $v->product->name.' ('.$v->name.')' : $v->product->name,
                    'price' => (string) $v->price,
                ],
            ]);
    }

    #[Computed]
    public function comboValue(): string
    {
        return collect($this->comboItems)->reduce(function ($total, $row) {
            $price = $this->comboChoices[(int) ($row['variant_id'] ?? 0)]['price'] ?? 0;

            return Money::add($total, Money::mul($price, max(0, (int) ($row['quantity'] ?? 0))));
        }, '0');
    }

    #[Computed]
    public function nextSku(): string
    {
        $next = ShopSequence::query()->where(['tenant_id' => $this->shop->id, 'key' => 'sku', 'scope' => ''])->value('next_value') ?? 1;

        return ShopSequence::format($this->shop->order_prefix, (int) $next, 'P', 4);
    }

    // ---------------------------------------------------------------- repeaters

    public function addVariant(): void
    {
        $this->variants[] = ['id' => null, 'name' => '', 'price' => '', 'is_available' => true];
    }

    public function removeVariant(int $index): void
    {
        if (count($this->variants) <= 1) {
            return;
        }

        unset($this->variants[$index]);
        $this->variants = array_values($this->variants);

        if ($this->defaultVariant >= count($this->variants) || $this->defaultVariant === $index) {
            $this->defaultVariant = 0;
        } elseif ($this->defaultVariant > $index) {
            $this->defaultVariant--;
        }
    }

    public function addComboItem(): void
    {
        $this->comboItems[] = ['variant_id' => '', 'quantity' => '1'];
    }

    public function removeComboItem(int $index): void
    {
        unset($this->comboItems[$index]);
        $this->comboItems = array_values($this->comboItems);
    }

    public function updatedType(string $value): void
    {
        if ($value === 'combo') {
            $this->variants = [array_merge($this->variants[0], ['name' => 'Regular'])];
            $this->defaultVariant = 0;

            if ($this->comboItems === []) {
                $this->comboItems = [['variant_id' => '', 'quantity' => '1']];
            }
        }
    }

    public function saveCategory(): void
    {
        $this->validate(['newCategory' => ['required', 'string', 'max:80',
            Rule::unique('categories', 'name')->where('tenant_id', $this->shop->id)]],
            ['newCategory.unique' => 'This category already exists.'], ['newCategory' => 'category name']);

        $category = Category::create([
            'name' => trim($this->newCategory),
            'sort_order' => (int) Category::query()->max('sort_order') + 1,
        ]);

        $this->categoryId = (string) $category->id;
        $this->reset('newCategory', 'addingCategory');
        unset($this->categories);
    }

    // ---------------------------------------------------------------- save

    public function save(bool $addAnother = false): void
    {
        $this->sku = Str::upper(trim($this->sku));
        $tenantId = $this->shop->id;
        $single = count($this->variants) === 1;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'sku' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($this->productId)],
            'type' => ['required', Rule::in(array_keys(Product::TYPES))],
            'description' => ['nullable', 'string', 'max:500'],
            'isAvailable' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'variants' => ['required', 'array', 'min:1', 'max:12'],
            'variants.*.name' => [$single ? 'nullable' : 'required', 'string', 'max:60', 'distinct:ignore_case'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.is_available' => ['boolean'],
            'modifierGroupIds' => ['array'],
            'modifierGroupIds.*' => [Rule::exists('modifier_groups', 'id')->where('tenant_id', $tenantId)],
            'comboItems' => [$this->type === 'combo' ? 'required' : 'nullable', 'array', 'max:20'],
            'comboItems.*.variant_id' => ['required', Rule::in($this->comboChoices->keys()->map(fn ($id) => (string) $id)->all())],
            'comboItems.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ], [
            'sku.regex' => 'Use capital letters, numbers, dashes or dots.',
            'sku.unique' => 'Another product already uses this SKU.',
            'variants.*.name.required' => 'Name this size, e.g. Regular or Large.',
            'variants.*.name.distinct' => 'Size names must be different.',
            'variants.*.price.required' => 'Enter a price.',
            'variants.*.price.numeric' => 'Enter a valid price.',
            'comboItems.required' => 'Add the items included in this deal.',
            'comboItems.*.variant_id.required' => 'Choose an item.',
            'comboItems.*.variant_id.in' => 'Choose an item from the list.',
        ], ['categoryId' => 'category', 'sku' => 'SKU']);

        // A size that is part of a deal cannot be removed.
        if ($this->productId) {
            $keptIds = collect($this->variants)->pluck('id')->filter()->all();
            $removed = ProductVariant::query()->where('product_id', $this->productId)->whereNotIn('id', $keptIds)->pluck('id');

            if ($removed->isNotEmpty() && ComboItem::query()->whereIn('product_variant_id', $removed)->exists()) {
                $this->addError('variants', 'A size you removed is part of a combo deal. Remove it from the deal first.');

                return;
            }
        }

        $product = DB::transaction(function () use ($single) {
            $product = $this->productId ? Product::query()->findOrFail($this->productId) : new Product;

            $product->fill([
                'name' => trim($this->name),
                'category_id' => $this->categoryId !== '' ? (int) $this->categoryId : null,
                'type' => $this->type,
                'description' => $this->description ?: null,
                'is_available' => $this->isAvailable,
            ]);

            if ($this->sku !== '') {
                $product->sku = $this->sku;
            }

            if (! $product->exists) {
                $product->sort_order = (int) Product::query()->where('category_id', $product->category_id)->max('sort_order') + 1;
            }

            if ($this->image) {
                $old = $product->image_path;
                $product->image_path = $this->image->store("shops/{$this->shop->id}/products", 'public');
                $old && Storage::disk('public')->delete($old);
            } elseif ($this->removeImage && $product->image_path) {
                Storage::disk('public')->delete($product->image_path);
                $product->image_path = null;
            }

            $product->save();

            $keep = [];
            foreach (array_values($this->variants) as $index => $row) {
                $attributes = [
                    'name' => $single ? (trim((string) $row['name']) ?: 'Regular') : trim($row['name']),
                    'price' => Money::of($row['price']),
                    'is_available' => (bool) $row['is_available'],
                    'is_default' => $index === $this->defaultVariant,
                    'sort_order' => $index + 1,
                ];

                $variant = ! empty($row['id']) ? $product->variants()->find($row['id']) : null;

                if ($variant) {
                    $variant->update($attributes);
                } else {
                    $variant = $product->variants()->create($attributes);
                }

                $keep[] = $variant->id;
            }

            $product->variants()->whereNotIn('id', $keep)->delete();

            $product->modifierGroups()->sync(
                collect($this->modifierGroupIds)->values()->mapWithKeys(fn ($id, $i) => [(int) $id => ['sort_order' => $i + 1]])->all()
            );

            $product->comboItems()->delete();

            if ($product->isCombo()) {
                foreach (array_values($this->comboItems) as $index => $row) {
                    $product->comboItems()->create([
                        'product_variant_id' => (int) $row['variant_id'],
                        'quantity' => (int) $row['quantity'],
                        'sort_order' => $index + 1,
                    ]);
                }
            }

            return $product;
        });

        session()->flash('toast', ['type' => 'success', 'message' => $product->name.' saved.']);

        $addAnother
            ? $this->redirectRoute('shop.products.create', array_filter(['category' => $product->category_id]), navigate: true)
            : $this->redirectRoute('shop.products.index', navigate: true);
    }

    public function archive(): void
    {
        $product = Product::query()->findOrFail($this->productId);

        $usedInDeals = ComboItem::query()
            ->whereIn('product_variant_id', $product->variants()->pluck('id'))
            ->whereHas('combo')
            ->exists();

        if ($usedInDeals) {
            $this->dispatch('toast', type: 'error', message: 'This product is part of a combo deal. Remove it from the deal first.');

            return;
        }

        $product->delete();

        session()->flash('toast', ['type' => 'success', 'message' => $product->name.' archived. You can restore it from Products → Archived.']);
        $this->redirectRoute('shop.products.index', navigate: true);
    }

    private function plain(string|float|null $amount): string
    {
        return rtrim(rtrim((string) $amount, '0'), '.') ?: '0';
    }
};
?>

<div>
    <x-page-header :title="$productId ? 'Edit product' : 'New product'"
                   :breadcrumbs="['Products' => route('shop.products.index'), ($productId ? $name : 'New product') => null]" />

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                {{-- Details --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Details</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <x-input class="col-12" model="name" label="Product name" required placeholder="e.g. Zinger Burger" autofocus />

                            <div class="col-md-6">
                                <label class="form-label" for="f-categoryId">Category</label>
                                @if ($addingCategory)
                                    <div class="input-group">
                                        <input type="text" wire:model="newCategory" wire:keydown.enter.prevent="saveCategory" placeholder="New category name"
                                               class="form-control form-control-touch @error('newCategory') is-invalid @enderror" aria-label="New category name">
                                        <button type="button" class="btn btn-primary" wire:click="saveCategory">Add</button>
                                        <button type="button" class="btn btn-light" wire:click="$set('addingCategory', false)" aria-label="Cancel"><i class="ri-close-line"></i></button>
                                    </div>
                                    @error('newCategory') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                @else
                                    <div class="input-group">
                                        <select id="f-categoryId" wire:model="categoryId" class="form-select form-select-touch @error('categoryId') is-invalid @enderror">
                                            <option value="">No category</option>
                                            @foreach ($this->categories as $category)
                                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="btn btn-light border" wire:click="$set('addingCategory', true)" title="New category"><i class="ri-add-line"></i></button>
                                    </div>
                                    @error('categoryId') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                @endif
                            </div>

                            <div class="col-md-6">
                                <span class="form-label d-block">Type</span>
                                <div class="btn-group w-100" role="group" aria-label="Product type">
                                    @foreach (\App\Models\Product::TYPES as $key => $label)
                                        <input type="radio" class="btn-check" id="type-{{ $key }}" value="{{ $key }}" wire:model.live="type">
                                        <label class="btn btn-outline-primary btn-touch" for="type-{{ $key }}">
                                            <i class="{{ $key === 'combo' ? 'ri-gift-line' : 'ri-restaurant-line' }}"></i> {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <x-textarea class="col-12" model="description" label="Description" rows="2" placeholder="Optional. Shown on receipts later and in online ordering." />
                        </div>
                    </div>
                </div>

                {{-- Price & sizes --}}
                <div class="card mb-3">
                    <div class="card-header hstack justify-content-between gap-2">
                        <h5 class="card-title mb-0">{{ count($variants) > 1 ? 'Sizes & prices' : 'Price' }}</h5>
                        @if ($type === 'simple')
                            <button type="button" class="btn btn-sm btn-light" wire:click="addVariant"><i class="ri-add-line"></i> Add size</button>
                        @endif
                    </div>
                    <div class="card-body">
                        @error('variants') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                        @if (count($variants) === 1)
                            <div class="row g-3 align-items-end">
                                <x-input class="col-sm-6" model="variants.0.price" label="Selling price" required inputmode="decimal"
                                         :prepend="$this->shop->currency_symbol" placeholder="0" />
                                @if ($type === 'simple')
                                    <div class="col-sm-6 form-text pb-2">Sold in different sizes? Use <strong>Add size</strong>, e.g. Regular and Large.</div>
                                @endif
                            </div>
                        @else
                            <div class="vstack gap-2">
                                @foreach ($variants as $index => $variant)
                                    <div class="border rounded-3 p-2 {{ $defaultVariant === $index ? 'border-primary' : '' }}" wire:key="variant-{{ $index }}">
                                        <div class="row g-2 align-items-start">
                                            <div class="col-12 col-sm-5">
                                                <input type="text" wire:model="variants.{{ $index }}.name" placeholder="Size name, e.g. Large" aria-label="Size name"
                                                       class="form-control form-control-touch @error('variants.'.$index.'.name') is-invalid @enderror">
                                                @error('variants.'.$index.'.name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                            </div>
                                            <div class="col-7 col-sm-4">
                                                <div class="input-group">
                                                    <span class="input-group-text">{{ $this->shop->currency_symbol }}</span>
                                                    <input type="text" inputmode="decimal" wire:model="variants.{{ $index }}.price" placeholder="0" aria-label="Price"
                                                           class="form-control form-control-touch @error('variants.'.$index.'.price') is-invalid @enderror">
                                                </div>
                                                @error('variants.'.$index.'.price') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                            </div>
                                            <div class="col-5 col-sm-3 hstack gap-1 justify-content-end">
                                                <input type="radio" class="btn-check" id="default-{{ $index }}" value="{{ $index }}" wire:model.live="defaultVariant">
                                                <label class="btn btn-sm {{ $defaultVariant === $index ? 'btn-primary' : 'btn-light' }}" for="default-{{ $index }}" title="Default size on the POS">
                                                    <i class="ri-star-{{ $defaultVariant === $index ? 'fill' : 'line' }}"></i>
                                                </label>
                                                <div class="form-check form-switch mb-0 ms-1" title="Available">
                                                    <input class="form-check-input sfms-switch" type="checkbox" role="switch" wire:model="variants.{{ $index }}.is_available" aria-label="Size available">
                                                </div>
                                                <button type="button" class="btn btn-sm btn-light" wire:click="removeVariant({{ $index }})" aria-label="Remove size">
                                                    <i class="ri-delete-bin-line"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="form-text mt-2"><i class="ri-star-fill text-primary"></i> = selected first on the POS. Switch off a size when it's sold out.</div>
                        @endif
                    </div>
                </div>

                {{-- Deal contents --}}
                @if ($type === 'combo')
                    <div class="card mb-3">
                        <div class="card-header hstack justify-content-between gap-2">
                            <h5 class="card-title mb-0">What's in the deal</h5>
                            <button type="button" class="btn btn-sm btn-light" wire:click="addComboItem"><i class="ri-add-line"></i> Add item</button>
                        </div>
                        <div class="card-body">
                            @error('comboItems') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
                            <div class="vstack gap-2">
                                @foreach ($comboItems as $index => $row)
                                    <div class="row g-2 align-items-start" wire:key="combo-{{ $index }}">
                                        <div class="col-12 col-sm-7">
                                            <select wire:model.live="comboItems.{{ $index }}.variant_id" aria-label="Item"
                                                    class="form-select form-select-touch @error('comboItems.'.$index.'.variant_id') is-invalid @enderror">
                                                <option value="">Choose an item&hellip;</option>
                                                @foreach ($this->comboChoices as $variantId => $choice)
                                                    <option value="{{ $variantId }}">{{ $choice['label'] }} — {{ $this->shop->money($choice['price']) }}</option>
                                                @endforeach
                                            </select>
                                            @error('comboItems.'.$index.'.variant_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-8 col-sm-3">
                                            <div class="input-group">
                                                <span class="input-group-text">Qty</span>
                                                <input type="number" min="1" max="50" inputmode="numeric" wire:model.live.debounce.400ms="comboItems.{{ $index }}.quantity"
                                                       aria-label="Quantity" class="form-control form-control-touch @error('comboItems.'.$index.'.quantity') is-invalid @enderror">
                                            </div>
                                        </div>
                                        <div class="col-4 col-sm-2 text-end">
                                            <button type="button" class="btn btn-light btn-touch" wire:click="removeComboItem({{ $index }})" aria-label="Remove item"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @if ($this->comboChoices->isEmpty())
                                <div class="alert alert-warning mt-3 mb-0">Add regular products first, then build deals from them.</div>
                            @else
                                @php($dealPrice = is_numeric($variants[0]['price'] ?? null) ? (float) $variants[0]['price'] : null)
                                @php($value = (float) $this->comboValue)
                                <div class="border-top mt-3 pt-3 hstack justify-content-between flex-wrap gap-2 fs-13">
                                    <span class="text-muted">Items bought separately: <strong class="text-body">{{ $this->shop->money($value) }}</strong></span>
                                    @if ($dealPrice !== null && $value > 0)
                                        @if ($dealPrice < $value)
                                            <span class="badge bg-success-subtle text-success fs-12">Customer saves {{ $this->shop->money($value - $dealPrice) }}</span>
                                        @elseif ($dealPrice > $value)
                                            <span class="badge bg-warning-subtle text-warning fs-12">Deal costs more than the items</span>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Add-ons --}}
                <div class="card mb-3">
                    <div class="card-header hstack justify-content-between gap-2">
                        <h5 class="card-title mb-0">Add-ons</h5>
                        <a href="{{ route('shop.modifiers.index') }}" class="btn btn-sm btn-light" wire:navigate>Manage add-ons</a>
                    </div>
                    <div class="card-body">
                        @if ($this->modifierGroups->isEmpty())
                            <p class="text-muted mb-0">No add-on groups yet. Create groups like <em>Extras</em> or <em>Spice level</em> under Add-ons.</p>
                        @else
                            <div class="row g-2">
                                @foreach ($this->modifierGroups as $group)
                                    <div class="col-md-6" wire:key="mg-{{ $group->id }}">
                                        <label class="border rounded-3 p-3 d-flex gap-3 h-100 cursor-pointer {{ in_array((string) $group->id, $modifierGroupIds, true) ? 'border-primary bg-primary-subtle' : '' }}">
                                            <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" value="{{ $group->id }}" wire:model.live="modifierGroupIds">
                                            <span class="min-w-0">
                                                <span class="fw-semibold d-block">{{ $group->name }}</span>
                                                <span class="fs-12 text-muted d-block">{{ $group->ruleLabel() }}</span>
                                                <span class="fs-12 text-muted d-block text-truncate">{{ $group->options->pluck('name')->implode(', ') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                {{-- Photo --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Photo</h5></div>
                    <div class="card-body">
                        @php($existing = $productId ? \App\Models\Product::query()->find($productId)?->imageUrl() : null)
                        <div class="sfms-product-thumb rounded-3 mb-3">
                            @if ($image && method_exists($image, 'temporaryUrl'))
                                <img src="{{ $image->temporaryUrl() }}" alt="New photo preview">
                            @elseif ($existing && ! $removeImage)
                                <img src="{{ $existing }}" alt="Current photo">
                            @else
                                <span><i class="ri-image-line"></i></span>
                            @endif
                        </div>
                        <div class="hstack gap-2 flex-wrap">
                            <label class="btn btn-light btn-touch mb-0">
                                <i class="ri-upload-2-line"></i> {{ $existing || $image ? 'Change photo' : 'Upload photo' }}
                                <input type="file" wire:model="image" accept="image/png,image/jpeg,image/webp" class="d-none">
                            </label>
                            @if ($image)
                                <button type="button" class="btn btn-link text-danger" wire:click="$set('image', null)">Cancel</button>
                            @elseif ($existing && ! $removeImage)
                                <button type="button" class="btn btn-link text-danger" wire:click="$set('removeImage', true)">Remove</button>
                            @endif
                        </div>
                        <div wire:loading wire:target="image" class="text-muted fs-13 mt-2">Uploading&hellip;</div>
                        @error('image') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        <div class="form-text">JPG, PNG or WebP up to 2 MB. A square photo looks best on the POS.</div>
                    </div>
                </div>

                {{-- Availability & code --}}
                <div class="card mb-3">
                    <div class="card-body vstack gap-3">
                        <x-switch model="isAvailable" label="Available to sell" description="Switch off when sold out. It stays on the menu, greyed out." />
                        <x-input model="sku" label="SKU / code" maxlength="40" class="text-uppercase"
                                 :placeholder="$productId ? '' : 'Auto: '.$this->nextSku"
                                 :hint="$productId ? null : 'Leave empty to number it automatically.'" />
                    </div>
                </div>

                @if ($productId)
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="fw-semibold mb-1">Archive product</div>
                            <p class="text-muted fs-13 mb-3">Removes it from the menu and POS. Past orders keep it, and you can restore it later.</p>
                            <button type="button" class="btn btn-outline-danger btn-touch" wire:click="archive"
                                    wire:confirm="Archive {{ $name }}?"><i class="ri-archive-line"></i> Archive</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card sfms-sticky-actions mb-0">
            <div class="card-body hstack justify-content-end gap-2 py-3 flex-wrap">
                <a href="{{ route('shop.products.index') }}" class="btn btn-light btn-touch me-auto" wire:navigate>Cancel</a>
                @unless ($productId)
                    <button type="button" class="btn btn-light btn-touch" wire:click="save(true)" wire:loading.attr="disabled" wire:target="save,image">Save &amp; add another</button>
                @endunless
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save,image">
                    <span wire:loading.remove wire:target="save"><i class="ri-save-3-line"></i> Save product</span>
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" role="status"></span>
                </button>
            </div>
        </div>
    </form>
</div>
