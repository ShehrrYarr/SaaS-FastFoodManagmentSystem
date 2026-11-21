<?php

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::admin')] #[Title('Super Admins')] class extends Component
{
    public bool $showCreateModal = false;
    public string $name = '';
    public string $email = '';
    public string $password = '';

    public bool $showPasswordModal = false;
    public ?int $passwordUserId = null;
    public string $newPassword = '';

    #[Computed]
    public function admins()
    {
        return User::superAdmins()->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function openCreate(): void
    {
        $this->reset('name', 'email');
        $this->password = Str::password(12, symbols: false);
        $this->resetValidation();
        $this->showCreateModal = true;
    }

    public function generatePassword(): void
    {
        $this->password = Str::password(12, symbols: false);
    }

    public function create(): void
    {
        $this->email = Str::lower(trim($this->email));

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('tenant_id')],
            'password' => ['required', 'string', 'min:10', 'max:100'],
        ], ['email.unique' => 'A Super Admin with this email already exists.']);

        User::create([
            'tenant_id' => null,
            'name' => trim($this->name),
            'email' => $this->email,
            'password' => $this->password,
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->showCreateModal = false;
        unset($this->admins);
        $this->dispatch('toast', type: 'success', message: 'Super Admin added.');
    }

    public function openResetPassword(int $id): void
    {
        $this->passwordUserId = $this->findAdmin($id)->id;
        $this->newPassword = Str::password(12, symbols: false);
        $this->resetValidation();
        $this->showPasswordModal = true;
    }

    public function generateNewPassword(): void
    {
        $this->newPassword = Str::password(12, symbols: false);
    }

    public function resetPassword(): void
    {
        $this->validate(['newPassword' => ['required', 'string', 'min:10', 'max:100']], [], ['newPassword' => 'password']);

        $admin = $this->findAdmin($this->passwordUserId);
        $admin->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();

        $this->showPasswordModal = false;
        $this->dispatch('toast', type: 'success', message: 'Password updated for '.$admin->name.'.');
    }

    public function toggleActive(int $id): void
    {
        $admin = $this->findAdmin($id);

        if ($admin->is(auth('admin')->user())) {
            $this->dispatch('toast', type: 'error', message: 'You cannot disable your own account.');

            return;
        }

        if ($admin->is_active && User::superAdmins()->where('is_active', true)->count() <= 1) {
            $this->dispatch('toast', type: 'error', message: 'At least one Super Admin must stay active.');

            return;
        }

        $admin->update(['is_active' => ! $admin->is_active]);
        unset($this->admins);
        $this->dispatch('toast', type: 'success', message: $admin->name.($admin->is_active ? ' enabled.' : ' disabled.'));
    }

    private function findAdmin(?int $id): User
    {
        return User::superAdmins()->findOrFail($id);
    }
};
?>

<div>
    <x-page-header title="Super Admins" :breadcrumbs="['Dashboard' => route('admin.dashboard'), 'Super Admins' => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate">
                <i class="ri-user-add-line"></i>Add Super Admin
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Last sign-in</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->admins as $admin)
                            <tr wire:key="admin-{{ $admin->id }}">
                                <td class="td-primary">
                                    <div class="hstack gap-3">
                                        <span class="avatar avatar-sm avatar-title rounded-circle bg-primary-subtle text-primary fw-semibold fs-12 border-0">{{ $admin->initials() }}</span>
                                        <span class="fw-semibold">{{ $admin->name }}</span>
                                        @if ($admin->is(auth('admin')->user()))
                                            <span class="badge bg-info-subtle text-info">You</span>
                                        @endif
                                    </div>
                                </td>
                                <td data-label="Email">{{ $admin->email }}</td>
                                <td data-label="Last sign-in" class="fs-13">{{ $admin->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                <td data-label="Status">
                                    <span class="badge {{ $admin->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                        {{ $admin->is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </td>
                                <td class="text-end td-actions">
                                    <div class="hstack gap-2 justify-content-end">
                                        <button type="button" class="btn btn-sm btn-light" wire:click="openResetPassword({{ $admin->id }})">
                                            <i class="ri-lock-password-line"></i> Reset password
                                        </button>
                                        @unless ($admin->is(auth('admin')->user()))
                                            <button type="button" class="btn btn-sm {{ $admin->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                                    wire:click="toggleActive({{ $admin->id }})"
                                                    wire:confirm="{{ $admin->is_active ? 'Disable '.$admin->name.'?' : 'Enable '.$admin->name.'?' }}">
                                                {{ $admin->is_active ? 'Disable' : 'Enable' }}
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal wire:model="showCreateModal" title="Add Super Admin">
        <form wire:submit="create" novalidate>
            <div class="modal-body vstack gap-3">
                <p class="text-muted mb-0">Super Admins can create, suspend and manage every shop.</p>
                <div>
                    <label class="form-label" for="adminName">Name<span class="text-danger ms-1">*</span></label>
                    <input type="text" id="adminName" wire:model="name" autocomplete="off" class="form-control form-control-touch @error('name') is-invalid @enderror">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label" for="adminEmail">Email<span class="text-danger ms-1">*</span></label>
                    <input type="email" id="adminEmail" wire:model="email" autocomplete="off" class="form-control form-control-touch @error('email') is-invalid @enderror">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <x-password-input model="password" id="adminPassword" autocomplete="new-password" generate="generatePassword" :required="true" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="create">Add Super Admin</button>
            </div>
        </form>
    </x-modal>

    <x-modal wire:model="showPasswordModal" title="Reset password">
        <form wire:submit="resetPassword" novalidate>
            <div class="modal-body">
                <x-password-input model="newPassword" id="adminNewPassword" label="New password" autocomplete="new-password" generate="generateNewPassword" :required="true" />
                <div class="form-text">At least 10 characters.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="resetPassword">Save password</button>
            </div>
        </form>
    </x-modal>
</div>
