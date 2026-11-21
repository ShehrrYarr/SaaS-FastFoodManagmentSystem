<?php

use App\Models\Role;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Roles')] class extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    #[Computed]
    public function roles()
    {
        return Role::query()->withCount('users')->orderByDesc('is_locked')->orderBy('id')->get();
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'description', 'permissions');
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $role = Role::query()->findOrFail($id);

        if ($role->is_locked) {
            $this->dispatch('toast', type: 'info', message: 'The Owner role always has every permission.');

            return;
        }

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->permissions = array_values(array_intersect($role->permissions ?? [], Permissions::all()));
        $this->resetValidation();
        $this->showModal = true;
    }

    public function duplicate(int $id): void
    {
        $role = Role::query()->findOrFail($id);

        $this->reset('editingId');
        $this->name = $role->name.' copy';
        $this->description = (string) $role->description;
        $this->permissions = $role->is_locked ? Permissions::all() : ($role->permissions ?? []);
        $this->resetValidation();
        $this->showModal = true;
    }

    public function toggleGroup(string $group): void
    {
        $keys = array_keys(Permissions::GROUPS[$group] ?? []);
        $allOn = ! array_diff($keys, $this->permissions);

        $this->permissions = $allOn
            ? array_values(array_diff($this->permissions, $keys))
            : array_values(array_unique(array_merge($this->permissions, $keys)));
    }

    public function save(): void
    {
        $editing = $this->editingId ? Role::query()->findOrFail($this->editingId) : null;
        abort_if($editing?->is_locked, 403);

        $this->validate([
            'name' => ['required', 'string', 'max:60',
                Rule::unique('roles', 'name')->where('tenant_id', app(TenantContext::class)->id())->ignore($editing?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::all())],
        ], ['name.unique' => 'A role with this name already exists.']);

        $attributes = [
            'name' => trim($this->name),
            'description' => $this->description ?: null,
            'permissions' => array_values(array_unique($this->permissions)),
        ];

        $editing ? $editing->update($attributes) : Role::create($attributes);

        $this->showModal = false;
        unset($this->roles);
        $this->dispatch('toast', type: 'success', message: 'Role '.$attributes['name'].' saved.');
    }

    public function delete(int $id): void
    {
        $role = Role::query()->withCount('users')->findOrFail($id);

        if ($role->is_locked || $role->users_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'Move its users to another role before deleting it.');

            return;
        }

        $role->delete();
        unset($this->roles);
        $this->dispatch('toast', type: 'success', message: 'Role deleted.');
    }
};
?>

<div>
    <x-page-header title="Roles" subtitle="Decide what each kind of staff member can do.">
        <x-slot:actions>
            <a href="{{ route('shop.staff.index') }}" class="btn btn-light btn-touch" wire:navigate><i class="ri-team-line"></i>Staff users</a>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-add-line"></i>New role</button>
        </x-slot:actions>
    </x-page-header>

    @php($total = count(\App\Support\Permissions::all()))

    <div class="row g-3">
        @foreach ($this->roles as $role)
            @php($count = $role->is_locked ? $total : count(array_intersect($role->permissions ?? [], \App\Support\Permissions::all())))
            <div class="col-md-6 col-xxl-4" wire:key="role-{{ $role->id }}">
                <div class="card h-100 mb-0">
                    <div class="card-body vstack gap-3">
                        <div class="hstack gap-3 align-items-start">
                            <span class="avatar avatar-md avatar-title rounded-3 border-0 {{ $role->is_locked ? 'bg-primary text-white' : 'bg-primary-subtle text-primary' }}">
                                <i class="{{ $role->is_locked ? 'ri-vip-crown-line' : 'ri-shield-user-line' }} fs-18"></i>
                            </span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold">{{ $role->name }} @if ($role->is_locked)<i class="ri-lock-line text-muted ms-1" title="Locked"></i>@endif</div>
                                <div class="fs-13 text-muted">{{ $role->description ?: 'No description' }}</div>
                            </div>
                        </div>
                        <div>
                            <div class="hstack justify-content-between fs-13 mb-1">
                                <span class="text-muted">{{ $count }} of {{ $total }} permissions</span>
                                <span class="text-muted">{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</span>
                            </div>
                            <div class="progress" style="height: 6px;" role="progressbar" aria-valuenow="{{ $count }}" aria-valuemin="0" aria-valuemax="{{ $total }}">
                                <div class="progress-bar" style="width: {{ $total ? round($count / $total * 100) : 0 }}%"></div>
                            </div>
                        </div>
                        <div class="hstack gap-2 mt-auto">
                            @if ($role->is_locked)
                                <span class="fs-12 text-muted flex-grow-1">Always has full access.</span>
                            @else
                                <button type="button" class="btn btn-sm btn-light" wire:click="openEdit({{ $role->id }})"><i class="ri-pencil-line"></i> Edit</button>
                            @endif
                            <button type="button" class="btn btn-sm btn-light" wire:click="duplicate({{ $role->id }})"><i class="ri-file-copy-line"></i> Copy</button>
                            @if (! $role->is_locked && $role->users_count === 0)
                                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" wire:click="delete({{ $role->id }})"
                                        wire:confirm="Delete the {{ $role->name }} role?"><i class="ri-delete-bin-line"></i></button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit role' : 'New role'" size="lg">
        <form wire:submit="save" novalidate>
            <div class="modal-body">
                <div class="row g-3 mb-4">
                    <x-input class="col-md-5" model="name" label="Role name" required placeholder="e.g. Shift supervisor" />
                    <x-input class="col-md-7" model="description" label="Description" placeholder="What this role is for" />
                </div>

                @foreach (\App\Support\Permissions::GROUPS as $group => $items)
                    @php($groupOn = ! array_diff(array_keys($items), $permissions))
                    <div class="border rounded-3 p-3 mb-3" wire:key="group-{{ \Illuminate\Support\Str::slug($group) }}">
                        <div class="hstack justify-content-between mb-2">
                            <span class="fw-semibold">{{ $group }}</span>
                            <button type="button" class="btn btn-sm btn-link p-0" wire:click="toggleGroup('{{ $group }}')">
                                {{ $groupOn ? 'Clear all' : 'Select all' }}
                            </button>
                        </div>
                        <div class="row g-2">
                            @foreach ($items as $key => $label)
                                <div class="col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="perm-{{ $key }}" value="{{ $key }}" wire:model="permissions">
                                        <label class="form-check-label" for="perm-{{ $key }}">{{ $label }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                @error('permissions.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save role</button>
            </div>
        </form>
    </x-modal>
</div>
