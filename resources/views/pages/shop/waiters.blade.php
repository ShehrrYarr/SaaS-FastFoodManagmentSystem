<?php

use App\Models\Waiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Waiters & Riders')] class extends Component
{
    #[Url(except: 'waiter')]
    public string $type = 'waiter';

    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $phone = '';
    public string $personType = 'waiter';

    #[Computed]
    public function people()
    {
        return Waiter::query()->where('type', $this->type)->orderByDesc('is_active')->orderBy('name')->get();
    }

    #[Computed]
    public function counts(): array
    {
        return Waiter::query()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')->all();
    }

    public function setType(string $type): void
    {
        $this->type = array_key_exists($type, Waiter::TYPES) ? $type : 'waiter';
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'phone');
        $this->personType = $this->type;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $person = Waiter::query()->findOrFail($id);

        $this->editingId = $person->id;
        $this->name = $person->name;
        $this->phone = (string) $person->phone;
        $this->personType = $person->type;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'personType' => ['required', Rule::in(array_keys(Waiter::TYPES))],
        ], [], ['personType' => 'role']);

        $attributes = ['name' => trim($this->name), 'phone' => $this->phone ?: null, 'type' => $this->personType];

        if ($this->editingId) {
            Waiter::query()->findOrFail($this->editingId)->update($attributes);
        } else {
            Waiter::create($attributes);
        }

        $this->showModal = false;
        $this->type = $this->personType;
        unset($this->people, $this->counts);
        $this->dispatch('toast', type: 'success', message: $attributes['name'].' saved.');
    }

    public function toggleActive(int $id): void
    {
        $person = Waiter::query()->findOrFail($id);
        $person->update(['is_active' => ! $person->is_active]);
        unset($this->people);
    }

    public function delete(int $id): void
    {
        $person = Waiter::query()->findOrFail($id);
        $person->delete();
        unset($this->people, $this->counts);
        $this->dispatch('toast', type: 'success', message: $person->name.' removed.');
    }
};
?>

<div>
    <x-page-header title="Waiters & Riders" subtitle="Names you tag on orders. They don't need to sign in.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate">
                <i class="ri-user-add-line"></i>Add {{ strtolower(\App\Models\Waiter::TYPES[$type]) }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="sfms-filter-tabs mb-3" role="tablist">
        @foreach (['waiter' => ['Waiters', 'ri-user-smile-line'], 'rider' => ['Delivery riders', 'ri-e-bike-2-line']] as $key => [$label, $icon])
            <button type="button" role="tab" wire:click="setType('{{ $key }}')" aria-selected="{{ $type === $key ? 'true' : 'false' }}"
                    class="btn btn-touch {{ $type === $key ? 'btn-primary' : 'btn-light' }}">
                <i class="{{ $icon }}"></i>{{ $label }}
                <span class="badge rounded-pill ms-1 {{ $type === $key ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary' }}">{{ $this->counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    @if ($this->people->isEmpty())
        <div class="card">
            <x-empty-state :icon="$type === 'rider' ? 'ri-e-bike-2-line' : 'ri-user-smile-line'" :title="'No '.strtolower(\App\Models\Waiter::TYPES[$type]).'s yet'">
                Add the people you want to pick when taking {{ $type === 'rider' ? 'delivery' : 'dine-in' }} orders.
            </x-empty-state>
        </div>
    @else
        <div class="row g-3">
            @foreach ($this->people as $person)
                <div class="col-sm-6 col-xl-4" wire:key="person-{{ $person->id }}">
                    <div class="card h-100 mb-0 {{ $person->is_active ? '' : 'opacity-50' }}">
                        <div class="card-body hstack gap-3">
                            <span class="avatar avatar-md avatar-title rounded-circle bg-primary-subtle text-primary fw-semibold border-0">{{ $person->initials() }}</span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold text-truncate">{{ $person->name }}</div>
                                <div class="fs-13 text-muted">{{ $person->phone ?: 'No phone' }} @unless ($person->is_active) &middot; Inactive @endunless</div>
                            </div>
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="Actions for {{ $person->name }}">
                                    <i class="ri-more-2-fill"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <button type="button" class="dropdown-item" wire:click="openEdit({{ $person->id }})"><i class="ri-pencil-line me-2"></i>Edit</button>
                                    <button type="button" class="dropdown-item" wire:click="toggleActive({{ $person->id }})">
                                        <i class="{{ $person->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }} me-2"></i>{{ $person->is_active ? 'Mark inactive' : 'Mark active' }}
                                    </button>
                                    <button type="button" class="dropdown-item text-danger" wire:click="delete({{ $person->id }})"
                                            wire:confirm="Remove {{ $person->name }}?"><i class="ri-delete-bin-line me-2"></i>Remove</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit person' : 'Add person'">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                <x-input model="name" label="Name" required />
                <x-input model="phone" label="Phone" type="tel" />
                <div>
                    <span class="form-label d-block">Role</span>
                    <div class="btn-group w-100" role="group" aria-label="Role">
                        @foreach (\App\Models\Waiter::TYPES as $key => $label)
                            <input type="radio" class="btn-check" id="role-{{ $key }}" value="{{ $key }}" wire:model="personType">
                            <label class="btn btn-outline-primary btn-touch" for="role-{{ $key }}">{{ $label }}</label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save</button>
            </div>
        </form>
    </x-modal>
</div>
