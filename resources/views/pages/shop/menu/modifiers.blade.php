<?php

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Add-ons')] class extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $minSelect = '0';
    public string $maxSelect = '';

    /** @var list<array{id: ?int, name: string, price: string, is_available: bool}> */
    public array $options = [];

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function groups()
    {
        return ModifierGroup::query()->ordered()->with('options')->withCount('products')->get();
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'maxSelect');
        $this->minSelect = '0';
        $this->options = [$this->blankOption(), $this->blankOption()];
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $group = ModifierGroup::query()->with('options')->findOrFail($id);

        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->minSelect = (string) $group->min_select;
        $this->maxSelect = $group->max_select === null ? '' : (string) $group->max_select;
        $this->options = $group->options->map(fn (ModifierOption $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'price' => rtrim(rtrim((string) $o->price, '0'), '.') ?: '0',
            'is_available' => $o->is_available,
        ])->all();
        $this->resetValidation();
        $this->showModal = true;
    }

    /** Preset rules: "pick one" (required) or "any" (optional). */
    public function preset(string $rule): void
    {
        [$this->minSelect, $this->maxSelect] = match ($rule) {
            'one' => ['1', '1'],
            'any' => ['0', ''],
            default => [$this->minSelect, $this->maxSelect],
        };
    }

    public function addOption(): void
    {
        $this->options[] = $this->blankOption();
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('modifier_groups', 'name')->where('tenant_id', $this->shop->id)->ignore($this->editingId)],
            'minSelect' => ['required', 'integer', 'min:0', 'max:20'],
            'maxSelect' => ['nullable', 'integer', 'min:1', 'max:20', 'gte:minSelect'],
            'options' => ['required', 'array', 'min:1', 'max:40'],
            'options.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'options.*.price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'options.*.is_available' => ['boolean'],
        ], [
            'name.unique' => 'An add-on group with this name already exists.',
            'maxSelect.gte' => 'Maximum must be at least the minimum.',
            'options.required' => 'Add at least one option.',
            'options.*.name.required' => 'Name this option.',
            'options.*.name.distinct' => 'Option names must be different.',
            'options.*.price.required' => 'Enter a price (0 for free).',
        ], ['minSelect' => 'minimum', 'maxSelect' => 'maximum']);

        if ((int) $this->minSelect > count($this->options)) {
            $this->addError('minSelect', 'The minimum cannot be more than the number of options.');

            return;
        }

        DB::transaction(function () {
            $group = $this->editingId ? ModifierGroup::query()->findOrFail($this->editingId) : new ModifierGroup;
            $group->fill([
                'name' => trim($this->name),
                'min_select' => (int) $this->minSelect,
                'max_select' => $this->maxSelect === '' ? null : (int) $this->maxSelect,
            ]);

            if (! $group->exists) {
                $group->sort_order = (int) ModifierGroup::query()->max('sort_order') + 1;
            }

            $group->save();

            $keep = [];
            foreach (array_values($this->options) as $index => $row) {
                $attributes = [
                    'name' => trim($row['name']),
                    'price' => $row['price'],
                    'is_available' => (bool) $row['is_available'],
                    'sort_order' => $index + 1,
                ];

                $option = ! empty($row['id']) ? $group->options()->find($row['id']) : null;

                if ($option) {
                    $option->update($attributes);
                } else {
                    $option = $group->options()->create($attributes);
                }

                $keep[] = $option->id;
            }

            $group->options()->whereNotIn('id', $keep)->delete();
        });

        $this->showModal = false;
        unset($this->groups);
        $this->dispatch('toast', type: 'success', message: 'Add-on group saved.');
    }

    public function delete(int $id): void
    {
        $group = ModifierGroup::query()->findOrFail($id);
        $group->delete();
        unset($this->groups);
        $this->dispatch('toast', type: 'success', message: $group->name.' deleted.');
    }

    private function blankOption(): array
    {
        return ['id' => null, 'name' => '', 'price' => '0', 'is_available' => true];
    }
};
?>

<div>
    <x-page-header title="Add-ons" subtitle="Extras and choices offered with products, e.g. Extra cheese or Spice level.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-add-line"></i>New add-on group</button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->groups->isEmpty())
        <div class="card">
            <x-empty-state icon="ri-add-circle-line" title="No add-on groups yet">
                Create a group like <strong>Extras</strong> (optional, paid) or <strong>Spice level</strong> (pick one), then attach it to products.
            </x-empty-state>
        </div>
    @else
        <div class="row g-3">
            @foreach ($this->groups as $group)
                <div class="col-md-6 col-xxl-4" wire:key="group-{{ $group->id }}">
                    <div class="card h-100 mb-0">
                        <div class="card-header hstack justify-content-between gap-2">
                            <div class="min-w-0">
                                <h5 class="card-title mb-0 text-truncate">{{ $group->name }}</h5>
                                <span class="fs-12 {{ $group->isRequired() ? 'text-warning' : 'text-muted' }}">{{ $group->ruleLabel() }}</span>
                            </div>
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="Actions for {{ $group->name }}">
                                    <i class="ri-more-2-fill"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <button type="button" class="dropdown-item" wire:click="openEdit({{ $group->id }})"><i class="ri-pencil-line me-2"></i>Edit</button>
                                    <button type="button" class="dropdown-item text-danger" wire:click="delete({{ $group->id }})"
                                            wire:confirm="Delete {{ $group->name }}? It will be removed from {{ $group->products_count }} products."><i class="ri-delete-bin-line me-2"></i>Delete</button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled vstack gap-2 mb-3">
                                @foreach ($group->options as $option)
                                    <li class="hstack justify-content-between {{ $option->is_available ? '' : 'text-muted text-decoration-line-through' }}">
                                        <span>{{ $option->name }}</span>
                                        <span class="fw-semibold">{{ (float) $option->price > 0 ? '+'.$this->shop->money($option->price) : 'Free' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="fs-13 text-muted"><i class="ri-links-line"></i> Used by {{ $group->products_count }} {{ Str::plural('product', $group->products_count) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit add-on group' : 'New add-on group'" size="lg">
        <form wire:submit="save" novalidate>
            <div class="modal-body">
                <x-input class="mb-4" model="name" label="Group name" required placeholder="e.g. Extras, Spice level, Choose your drink" />

                <span class="form-label d-block">How many can the customer choose?</span>
                <div class="hstack gap-2 flex-wrap mb-3">
                    <button type="button" class="btn btn-sm {{ $minSelect === '1' && $maxSelect === '1' ? 'btn-primary' : 'btn-light' }}" wire:click="preset('one')">Exactly one (required)</button>
                    <button type="button" class="btn btn-sm {{ $minSelect === '0' && $maxSelect === '' ? 'btn-primary' : 'btn-light' }}" wire:click="preset('any')">Any number (optional)</button>
                </div>
                <div class="row g-3 mb-4">
                    <x-input class="col-6" model="minSelect" label="Minimum" type="number" min="0" inputmode="numeric" modifier="live" hint="0 = optional" />
                    <x-input class="col-6" model="maxSelect" label="Maximum" type="number" min="1" inputmode="numeric" modifier="live" hint="Empty = no limit" />
                </div>

                <span class="form-label d-block">Options</span>
                @error('options') <div class="invalid-feedback d-block mb-2">{{ $message }}</div> @enderror
                <div class="vstack gap-2">
                    @foreach ($options as $index => $option)
                        <div class="border rounded-3 p-2" wire:key="option-{{ $index }}">
                            <div class="row g-2 align-items-start">
                                <div class="col-12 col-sm-6">
                                    <input type="text" wire:model="options.{{ $index }}.name" placeholder="Option name" aria-label="Option name"
                                           class="form-control form-control-touch @error('options.'.$index.'.name') is-invalid @enderror">
                                    @error('options.'.$index.'.name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6 col-sm-3">
                                    <div class="input-group">
                                        <span class="input-group-text">+{{ $this->shop->currency_symbol }}</span>
                                        <input type="text" inputmode="decimal" wire:model="options.{{ $index }}.price" aria-label="Extra price"
                                               class="form-control form-control-touch @error('options.'.$index.'.price') is-invalid @enderror">
                                    </div>
                                    @error('options.'.$index.'.price') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6 col-sm-3 hstack gap-2 justify-content-end">
                                    <div class="form-check form-switch mb-0" title="Available">
                                        <input class="form-check-input sfms-switch" type="checkbox" role="switch" wire:model="options.{{ $index }}.is_available" aria-label="Available">
                                    </div>
                                    <button type="button" class="btn btn-light btn-touch" wire:click="removeOption({{ $index }})" aria-label="Remove option" @disabled(count($options) === 1)>
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn btn-light btn-touch mt-2" wire:click="addOption"><i class="ri-add-line"></i> Add option</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save group</button>
            </div>
        </form>
    </x-modal>
</div>
