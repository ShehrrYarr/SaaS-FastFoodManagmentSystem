<?php

use App\Models\DiningTable;
use App\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Tables')] class extends Component
{
    // Add / edit one table
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $area = '';
    public string $capacity = '';

    // Add many at once: T-01 ... T-10
    public bool $showBulkModal = false;
    public string $bulkPrefix = 'T-';
    public string $bulkArea = '';
    public int $bulkFrom = 1;
    public int $bulkCount = 10;

    #[Computed]
    public function tables()
    {
        return DiningTable::query()->ordered()->get();
    }

    #[Computed]
    public function areas()
    {
        return $this->tables->pluck('area')->filter()->unique()->sort()->values();
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'capacity');
        $this->area = (string) $this->areas->first();
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $table = DiningTable::query()->findOrFail($id);

        $this->editingId = $table->id;
        $this->name = $table->name;
        $this->area = (string) $table->area;
        $this->capacity = (string) $table->capacity;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenantId = app(TenantContext::class)->id();

        $this->validate([
            'name' => ['required', 'string', 'max:30',
                Rule::unique('dining_tables', 'name')->where('tenant_id', $tenantId)->ignore($this->editingId)],
            'area' => ['nullable', 'string', 'max:60'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['name.unique' => 'A table with this name already exists.'], ['name' => 'table name']);

        $attributes = [
            'name' => trim($this->name),
            'area' => trim($this->area) ?: null,
            'capacity' => $this->capacity !== '' ? (int) $this->capacity : null,
        ];

        if ($this->editingId) {
            DiningTable::query()->findOrFail($this->editingId)->update($attributes);
        } else {
            DiningTable::create($attributes + ['sort_order' => (int) DiningTable::query()->max('sort_order') + 1]);
        }

        $this->showModal = false;
        unset($this->tables, $this->areas);
        $this->dispatch('toast', type: 'success', message: 'Table '.$attributes['name'].' saved.');
    }

    public function openBulk(): void
    {
        $this->bulkArea = (string) $this->areas->first();
        $this->bulkFrom = $this->tables->count() + 1;
        $this->resetValidation();
        $this->showBulkModal = true;
    }

    public function bulkCreate(): void
    {
        $this->validate([
            'bulkPrefix' => ['nullable', 'string', 'max:10'],
            'bulkArea' => ['nullable', 'string', 'max:60'],
            'bulkFrom' => ['required', 'integer', 'min:0', 'max:999'],
            'bulkCount' => ['required', 'integer', 'min:1', 'max:50'],
        ], [], ['bulkFrom' => 'start number', 'bulkCount' => 'number of tables']);

        $existing = $this->tables->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $sort = (int) DiningTable::query()->max('sort_order');
        $created = 0;

        for ($i = $this->bulkFrom; $i < $this->bulkFrom + $this->bulkCount; $i++) {
            $name = $this->bulkPrefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }

            DiningTable::create(['name' => $name, 'area' => trim($this->bulkArea) ?: null, 'sort_order' => ++$sort]);
            $created++;
        }

        $this->showBulkModal = false;
        unset($this->tables, $this->areas);
        $this->dispatch('toast', type: 'success', message: $created.' '.str('table')->plural($created).' added.');
    }

    public function toggleActive(int $id): void
    {
        $table = DiningTable::query()->findOrFail($id);
        $table->update(['is_active' => ! $table->is_active]);
        unset($this->tables);
    }

    public function delete(int $id): void
    {
        $table = DiningTable::query()->findOrFail($id);
        $table->delete();
        unset($this->tables, $this->areas);
        $this->dispatch('toast', type: 'success', message: 'Table '.$table->name.' deleted.');
    }
};
?>

<div>
    <x-page-header title="Tables" subtitle="Dine-in tables that open tabs can be assigned to.">
        <x-slot:actions>
            <button type="button" class="btn btn-light btn-touch" wire:click="openBulk"><i class="ri-stack-line"></i>Add many</button>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-add-line"></i>Add table</button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->tables->isEmpty())
        <div class="card">
            <x-empty-state icon="ri-layout-grid-line" title="No tables yet">
                Use <strong>Add many</strong> to create T-01 to T-10 in one go.
            </x-empty-state>
        </div>
    @else
        @foreach ($this->tables->groupBy(fn ($t) => $t->area ?: 'Tables') as $area => $tables)
            <h6 class="text-muted text-uppercase fs-12 fw-semibold mb-2 mt-1">{{ $area }} <span class="fw-normal">({{ $tables->count() }})</span></h6>
            <div class="row g-3 mb-4">
                @foreach ($tables as $table)
                    <div class="col-6 col-sm-4 col-md-3 col-xxl-2" wire:key="table-{{ $table->id }}">
                        <div class="card h-100 mb-0 text-center {{ $table->is_active ? '' : 'opacity-50' }}">
                            <div class="card-body p-3 position-relative">
                                <div class="dropdown position-absolute top-0 end-0 m-1">
                                    <button type="button" class="btn btn-sm btn-text-secondary icon-btn-sm" data-bs-toggle="dropdown" aria-label="Table actions">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <button type="button" class="dropdown-item" wire:click="openEdit({{ $table->id }})"><i class="ri-pencil-line me-2"></i>Edit</button>
                                        <button type="button" class="dropdown-item" wire:click="toggleActive({{ $table->id }})">
                                            <i class="{{ $table->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }} me-2"></i>{{ $table->is_active ? 'Hide from POS' : 'Show on POS' }}
                                        </button>
                                        <button type="button" class="dropdown-item text-danger" wire:click="delete({{ $table->id }})"
                                                wire:confirm="Delete table {{ $table->name }}?"><i class="ri-delete-bin-line me-2"></i>Delete</button>
                                    </div>
                                </div>
                                <div class="avatar avatar-md avatar-title rounded-3 bg-primary-subtle text-primary border-0 mx-auto mb-2">
                                    <i class="ri-restaurant-2-line fs-18"></i>
                                </div>
                                <div class="fw-bold fs-5">{{ $table->name }}</div>
                                <div class="fs-12 text-muted">
                                    {{ $table->capacity ? $table->capacity.' seats' : 'Seats not set' }}
                                    @unless ($table->is_active) &middot; Hidden @endunless
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    <datalist id="table-areas">
        @foreach ($this->areas as $existingArea)
            <option value="{{ $existingArea }}">
        @endforeach
    </datalist>

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit table' : 'Add table'">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                <x-input model="name" label="Table name" required placeholder="e.g. T-11" />
                <x-input model="area" label="Area" list="table-areas" placeholder="e.g. Hall, Rooftop, Family" hint="Optional. Groups tables on the POS." />
                <x-input model="capacity" label="Seats" type="number" min="1" max="100" inputmode="numeric" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save</button>
            </div>
        </form>
    </x-modal>

    <x-modal wire:model="showBulkModal" title="Add many tables">
        <form wire:submit="bulkCreate" novalidate>
            <div class="modal-body">
                <div class="row g-3">
                    <x-input class="col-4" model="bulkPrefix" label="Prefix" modifier="live" />
                    <x-input class="col-4" model="bulkFrom" label="Start at" type="number" min="0" inputmode="numeric" modifier="live" />
                    <x-input class="col-4" model="bulkCount" label="How many" type="number" min="1" max="50" inputmode="numeric" modifier="live" />
                    <x-input class="col-12" model="bulkArea" label="Area" list="table-areas" placeholder="Optional" />
                </div>
                @if ($bulkCount > 0 && $bulkCount <= 50)
                    <div class="alert alert-light mt-3 mb-0 fs-13">
                        Creates <span class="sfms-mono">{{ $bulkPrefix }}{{ str_pad((string) $bulkFrom, 2, '0', STR_PAD_LEFT) }}</span>
                        to <span class="sfms-mono">{{ $bulkPrefix }}{{ str_pad((string) ((int) $bulkFrom + (int) $bulkCount - 1), 2, '0', STR_PAD_LEFT) }}</span>.
                        Names that already exist are skipped.
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="bulkCreate">Add tables</button>
            </div>
        </form>
    </x-modal>
</div>
