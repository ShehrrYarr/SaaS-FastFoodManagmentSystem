<?php

use App\Models\Category;
use App\Models\ComboItem;
use App\Models\Product;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Products')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    /** '' | available | unavailable | deals | archived */
    #[Url(except: '')]
    public string $show = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
        $this->resetPage();
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function categories()
    {
        return Category::query()->ordered()->withCount('products')->get();
    }

    #[Computed]
    public function products()
    {
        $term = trim($this->search);

        return Product::query()
            ->with(['category', 'variants'])
            ->when($this->show === 'archived', fn (Builder $q) => $q->onlyTrashed())
            ->when($this->show === 'available', fn (Builder $q) => $q->where('is_available', true))
            ->when($this->show === 'unavailable', fn (Builder $q) => $q->where('is_available', false))
            ->when($this->show === 'deals', fn (Builder $q) => $q->where('type', 'combo'))
            ->when($this->category === 'none', fn (Builder $q) => $q->whereNull('category_id'))
            ->when(ctype_digit($this->category), fn (Builder $q) => $q->where('category_id', (int) $this->category))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")))
            ->ordered()
            ->paginate(24);
    }

    public function toggleAvailable(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        $product->update(['is_available' => ! $product->is_available]);

        $this->dispatch('toast', type: 'success', message: $product->name.($product->is_available ? ' is available.' : ' is marked sold out.'));
    }

    public function restore(int $id): void
    {
        $product = Product::query()->onlyTrashed()->findOrFail($id);
        $product->restore();
        $product->variants()->onlyTrashed()->restore();

        $this->dispatch('toast', type: 'success', message: $product->name.' restored.');
    }
};
?>

<div>
    <x-page-header title="Products" subtitle="Everything you sell, with sizes, add-ons and deals.">
        <x-slot:actions>
            <a href="{{ route('shop.categories.index') }}" class="btn btn-light btn-touch" wire:navigate><i class="ri-folder-3-line"></i>Categories</a>
            <a href="{{ route('shop.products.create', array_filter(['category' => ctype_digit($category) ? $category : null])) }}"
               class="btn btn-primary btn-touch" wire:navigate><i class="ri-add-line"></i>New product</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-3">
        <div class="card-body vstack gap-3">
            <div class="row g-2">
                <div class="col-md-8">
                    <div class="form-icon">
                        <input type="search" wire:model.live.debounce.300ms="search" class="form-control form-control-icon form-control-touch"
                               placeholder="Search by name or SKU" aria-label="Search products">
                        <i class="ri-search-2-line text-muted"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <select wire:model.live="show" class="form-select form-select-touch" aria-label="Show">
                        <option value="">All products</option>
                        <option value="available">Available</option>
                        <option value="unavailable">Sold out / hidden</option>
                        <option value="deals">Combo deals</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
            </div>
            <div class="sfms-filter-tabs" role="tablist" aria-label="Filter by category">
                <button type="button" wire:click="setCategory('')" class="btn btn-sm {{ $category === '' ? 'btn-primary' : 'btn-light' }}">All</button>
                @foreach ($this->categories as $cat)
                    <button type="button" wire:key="cat-{{ $cat->id }}" wire:click="setCategory('{{ $cat->id }}')"
                            class="btn btn-sm {{ $category === (string) $cat->id ? 'btn-primary' : 'btn-light' }}">
                        {{ $cat->name }} <span class="opacity-75">{{ $cat->products_count }}</span>
                    </button>
                @endforeach
                <button type="button" wire:click="setCategory('none')" class="btn btn-sm {{ $category === 'none' ? 'btn-primary' : 'btn-light' }}">No category</button>
            </div>
        </div>
    </div>

    @if ($this->products->isEmpty())
        <div class="card">
            <x-empty-state icon="ri-restaurant-line" title="No products found">
                @if ($search !== '' || $category !== '' || $show !== '')
                    Try a different search or filter.
                @else
                    <a href="{{ route('shop.products.create') }}" wire:navigate>Add your first product</a>
                @endif
            </x-empty-state>
        </div>
    @else
        <div class="row g-3" wire:loading.class="opacity-50" wire:target="search,show,setCategory,gotoPage,nextPage,previousPage">
            @foreach ($this->products as $product)
                @php($archived = $product->trashed())
                <div class="col-6 col-md-4 col-xl-3 col-xxl-2" wire:key="product-{{ $product->id }}">
                    <div class="card h-100 mb-0 sfms-product-card {{ $product->is_available && ! $archived ? '' : 'is-off' }}">
                        <a href="{{ $archived ? '#' : route('shop.products.edit', ['product' => $product]) }}" @unless ($archived) wire:navigate @endunless
                           class="sfms-product-thumb" aria-label="Edit {{ $product->name }}">
                            @if ($product->imageUrl())
                                <img src="{{ $product->imageUrl() }}" alt="" loading="lazy">
                            @else
                                <span>{{ $product->initials() }}</span>
                            @endif
                            @if ($product->isCombo()) <span class="badge bg-warning text-dark sfms-product-badge">Deal</span> @endif
                        </a>
                        <div class="card-body p-3">
                            <div class="fw-semibold text-truncate" title="{{ $product->name }}">{{ $product->name }}</div>
                            <div class="fs-12 text-muted text-truncate">{{ $product->category?->name ?? 'No category' }} &middot; <span class="sfms-mono">{{ $product->sku }}</span></div>
                            <div class="fw-bold mt-1">{{ $product->priceLabel($this->shop) }}</div>
                            @if ($product->variants->count() > 1)
                                <div class="fs-12 text-muted">{{ $product->variants->pluck('name')->implode(' · ') }}</div>
                            @endif
                        </div>
                        <div class="card-footer hstack justify-content-between gap-2 px-3 py-2">
                            @if ($archived)
                                <span class="fs-12 text-muted">Archived</span>
                                <button type="button" class="btn btn-sm btn-light" wire:click="restore({{ $product->id }})">Restore</button>
                            @else
                                <div class="form-check form-switch mb-0" title="{{ $product->is_available ? 'Available' : 'Sold out' }}">
                                    <input class="form-check-input sfms-switch" type="checkbox" role="switch" @checked($product->is_available)
                                           wire:click="toggleAvailable({{ $product->id }})" aria-label="{{ $product->name }} available">
                                </div>
                                <a href="{{ route('shop.products.edit', ['product' => $product]) }}" class="btn btn-sm btn-light" wire:navigate>Edit</a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($this->products->hasPages())
            <div class="mt-3">{{ $this->products->links() }}</div>
        @endif
    @endif
</div>
