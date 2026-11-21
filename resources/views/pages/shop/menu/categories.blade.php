<?php

use App\Models\Category;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Categories')] class extends Component
{
    public string $newName = '';

    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';

    #[Computed]
    public function categories()
    {
        return Category::query()->ordered()->withCount('products')->get();
    }

    protected function uniqueName(?int $ignore = null)
    {
        return Rule::unique('categories', 'name')->where('tenant_id', app(TenantContext::class)->id())->ignore($ignore);
    }

    public function add(): void
    {
        $this->validate(
            ['newName' => ['required', 'string', 'max:80', $this->uniqueName()]],
            ['newName.unique' => 'This category already exists.'],
            ['newName' => 'category name'],
        );

        Category::create([
            'name' => trim($this->newName),
            'sort_order' => (int) Category::query()->max('sort_order') + 1,
        ]);

        $this->reset('newName');
        unset($this->categories);
        $this->dispatch('toast', type: 'success', message: 'Category added.');
    }

    public function openEdit(int $id): void
    {
        $category = Category::query()->findOrFail($id);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate(
            ['name' => ['required', 'string', 'max:80', $this->uniqueName($this->editingId)]],
            ['name.unique' => 'This category already exists.'],
        );

        Category::query()->findOrFail($this->editingId)->update(['name' => trim($this->name)]);

        $this->showModal = false;
        unset($this->categories);
        $this->dispatch('toast', type: 'success', message: 'Category renamed.');
    }

    public function toggleActive(int $id): void
    {
        $category = Category::query()->findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
        unset($this->categories);
    }

    /** Drag and drop: move $id to $position (0-based). */
    public function sort(int $id, int $position): void
    {
        $ids = $this->categories->pluck('id')->reject(fn ($existing) => $existing === $id)->values()->all();
        array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $categoryId) {
                Category::query()->whereKey($categoryId)->update(['sort_order' => $index + 1]);
            }
        });

        unset($this->categories);
    }

    public function delete(int $id): void
    {
        $category = Category::query()->withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'Move or archive its '.$category->products_count.' products first.');

            return;
        }

        $category->delete();
        unset($this->categories);
        $this->dispatch('toast', type: 'success', message: 'Category deleted.');
    }
};
?>

<div>
    <x-page-header title="Categories" subtitle="Group products on the POS. Drag to set the order cashiers see.">
        <x-slot:actions>
            <a href="{{ route('shop.products.index') }}" class="btn btn-light btn-touch" wire:navigate><i class="ri-restaurant-line"></i>Products</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row justify-content-center">
        <div class="col-xl-8">
            <form wire:submit="add" class="card mb-3" novalidate>
                <div class="card-body">
                    <label class="form-label" for="newName">New category</label>
                    <div class="input-group">
                        <input type="text" id="newName" wire:model="newName" placeholder="e.g. Burgers, Fries & Sides, Drinks"
                               class="form-control form-control-touch @error('newName') is-invalid @enderror">
                        <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="add"><i class="ri-add-line"></i> Add</button>
                    </div>
                    @error('newName') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </form>

            <div class="card mb-0">
                @if ($this->categories->isEmpty())
                    <x-empty-state icon="ri-folder-3-line" title="No categories yet">Add your first category above.</x-empty-state>
                @else
                    <ul class="list-group list-group-flush" wire:sort="sort">
                        @foreach ($this->categories as $category)
                            <li class="list-group-item hstack gap-3 py-3 {{ $category->is_active ? '' : 'opacity-50' }}"
                                wire:key="category-{{ $category->id }}" wire:sort:item="{{ $category->id }}">
                                <span class="text-muted fs-20 cursor-move px-1" wire:sort:handle title="Drag to reorder" aria-label="Drag to reorder">
                                    <i class="ri-draggable"></i>
                                </span>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="fw-semibold text-truncate">{{ $category->name }}</div>
                                    <a href="{{ route('shop.products.index', ['category' => $category->id]) }}" class="fs-13 link-secondary" wire:navigate>
                                        {{ $category->products_count }} {{ Str::plural('product', $category->products_count) }}
                                    </a>
                                    @unless ($category->is_active) <span class="fs-13 text-muted">&middot; Hidden on POS</span> @endunless
                                </div>
                                <div class="form-check form-switch mb-0" wire:sort:ignore title="Show on POS">
                                    <input class="form-check-input sfms-switch" type="checkbox" role="switch" @checked($category->is_active)
                                           wire:click="toggleActive({{ $category->id }})" aria-label="Show {{ $category->name }} on POS">
                                </div>
                                <div class="dropdown" wire:sort:ignore>
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="Actions for {{ $category->name }}">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <button type="button" class="dropdown-item" wire:click="openEdit({{ $category->id }})"><i class="ri-pencil-line me-2"></i>Rename</button>
                                        <button type="button" class="dropdown-item text-danger" wire:click="delete({{ $category->id }})"
                                                wire:confirm="Delete {{ $category->name }}?"><i class="ri-delete-bin-line me-2"></i>Delete</button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <x-modal wire:model="showModal" title="Rename category">
        <form wire:submit="save" novalidate>
            <div class="modal-body">
                <x-input model="name" label="Name" required />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save</button>
            </div>
        </form>
    </x-modal>
</div>
