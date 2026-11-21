<?php

use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Staff users')] class extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $username = '';
    public string $phone = '';
    public string $password = '';
    public ?int $roleId = null;

    public bool $showPasswordModal = false;
    public ?int $passwordUserId = null;
    public string $newPassword = '';

    /** Login details shown once after adding a user or resetting a password. */
    public ?array $handover = null;

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function me(): User
    {
        return auth('shop')->user();
    }

    #[Computed]
    public function users()
    {
        return User::ofShop($this->shop)->with('role')->orderByDesc('is_active')->orderBy('name')->get();
    }

    #[Computed]
    public function roles()
    {
        return Role::query()->orderByDesc('is_locked')->orderBy('id')->get();
    }

    /** Only owners can hand out the Owner role. */
    #[Computed]
    public function assignableRoles()
    {
        return $this->me->isOwner() ? $this->roles : $this->roles->where('is_locked', false)->values();
    }

    /** Non-owners cannot change owners. */
    public function canManage(User $user): bool
    {
        return $this->me->isOwner() || ! $user->isOwner();
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'email', 'username', 'phone');
        $this->password = Str::password(10, symbols: false);
        $this->roleId = $this->assignableRoles->firstWhere('name', 'Cashier')?->id ?? $this->assignableRoles->last()?->id;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $user = $this->findManageable($id);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = (string) $user->email;
        $this->username = (string) $user->username;
        $this->phone = (string) $user->phone;
        $this->roleId = $user->role_id;
        $this->password = '';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function generatePassword(): void
    {
        $this->password = Str::password(10, symbols: false);
    }

    public function save(): void
    {
        $editing = $this->editingId ? $this->findManageable($this->editingId) : null;
        $this->email = Str::lower(trim($this->email));
        $this->username = Str::lower(trim($this->username));

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:username', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $this->shop->id)->ignore($editing?->id)],
            'username' => ['nullable', 'required_without:email', 'alpha_dash', 'min:3', 'max:60',
                Rule::unique('users', 'username')->where('tenant_id', $this->shop->id)->ignore($editing?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'roleId' => ['required', Rule::in($this->assignableRoles->pluck('id')->all())],
            'password' => [$editing ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
        ], [
            'email.required_without' => 'Enter an email or a username.',
            'username.required_without' => 'Enter an email or a username.',
            'email.unique' => 'Someone in this shop already uses this email.',
            'username.unique' => 'Someone in this shop already uses this username.',
            'roleId.in' => 'Choose a role you are allowed to give.',
        ], ['roleId' => 'role']);

        if ($editing && $editing->is($this->me) && (int) $this->roleId !== (int) $editing->role_id) {
            $this->addError('roleId', 'You cannot change your own role.');

            return;
        }

        $attributes = [
            'name' => trim($this->name),
            'email' => $this->email ?: null,
            'username' => $this->username ?: null,
            'phone' => $this->phone ?: null,
            'role_id' => $this->roleId,
        ];

        if ($editing) {
            $editing->update($attributes);
            $this->dispatch('toast', type: 'success', message: $editing->name.' updated.');
        } else {
            $user = User::create($attributes + ['tenant_id' => $this->shop->id, 'password' => $this->password]);
            $this->handover = ['name' => $user->name, 'login' => $user->loginName(), 'password' => $this->password];
            $this->dispatch('toast', type: 'success', message: $user->name.' can now sign in.');
        }

        $this->showModal = false;
        unset($this->users);
    }

    public function openResetPassword(int $id): void
    {
        $this->passwordUserId = $this->findManageable($id)->id;
        $this->newPassword = Str::password(10, symbols: false);
        $this->resetValidation();
        $this->showPasswordModal = true;
    }

    public function generateNewPassword(): void
    {
        $this->newPassword = Str::password(10, symbols: false);
    }

    public function resetPassword(): void
    {
        $this->validate(['newPassword' => ['required', 'string', 'min:8', 'max:100']], [], ['newPassword' => 'password']);

        $user = $this->findManageable($this->passwordUserId);
        $user->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();

        $this->handover = ['name' => $user->name, 'login' => $user->loginName(), 'password' => $this->newPassword];
        $this->showPasswordModal = false;
        $this->dispatch('toast', type: 'success', message: 'Password reset for '.$user->name.'.');
    }

    public function toggleActive(int $id): void
    {
        $user = $this->findManageable($id);

        if ($user->is($this->me)) {
            $this->dispatch('toast', type: 'error', message: 'You cannot disable your own account.');

            return;
        }

        if ($user->is_active && $user->isOwner()
            && $this->users->filter(fn (User $u) => $u->is_active && $u->isOwner())->count() <= 1) {
            $this->dispatch('toast', type: 'error', message: 'This is the only active owner.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
        unset($this->users);
        $this->dispatch('toast', type: 'success', message: $user->name.($user->is_active ? ' can sign in again.' : ' can no longer sign in.'));
    }

    public function dismissHandover(): void
    {
        $this->handover = null;
    }

    private function findManageable(?int $id): User
    {
        $user = User::ofShop($this->shop)->with('role')->findOrFail($id);

        abort_unless($this->canManage($user), 403, 'Only an owner can change another owner.');

        return $user;
    }
};
?>

<div>
    <x-page-header title="Staff users" subtitle="People who sign in to this shop. Waiters and riders don't need accounts.">
        <x-slot:actions>
            <a href="{{ route('shop.roles.index') }}" class="btn btn-light btn-touch" wire:navigate><i class="ri-shield-keyhole-line"></i>Roles</a>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-user-add-line"></i>Add user</button>
        </x-slot:actions>
    </x-page-header>

    @if ($handover)
        @php($copyText = "Sign-in page: {$this->shop->loginUrl()}\nLogin: {$handover['login']}\nPassword: {$handover['password']}")
        <div class="alert alert-success sfms-banner" role="alert">
            <div class="hstack gap-3 align-items-start">
                <i class="ri-key-2-line fs-20 flex-shrink-0"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold mb-1">Login details for {{ $handover['name'] }}</div>
                    <div class="sfms-mono fs-13 text-break">{{ $this->shop->loginUrl() }}</div>
                    <div class="sfms-mono fs-13">Login: {{ $handover['login'] }} &middot; Password: {{ $handover['password'] }}</div>
                    <div class="fs-12 mt-1">Share these now. The password will not be shown again.</div>
                </div>
                <div class="vstack gap-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-success" data-copy="{{ $copyText }}"><i class="ri-file-copy-line"></i> Copy</button>
                    <button type="button" class="btn btn-sm btn-light" wire:click="dismissHandover">Done</button>
                </div>
            </div>
        </div>
    @endif

    <div class="card mb-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Signs in with</th><th>Role</th><th>Last sign-in</th><th>Status</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($this->users as $user)
                            @php($manageable = $this->canManage($user))
                            <tr wire:key="user-{{ $user->id }}" class="{{ $user->is_active ? '' : 'opacity-75' }}">
                                <td class="td-primary">
                                    <div class="hstack gap-3">
                                        <span class="avatar avatar-sm avatar-title rounded-circle bg-primary-subtle text-primary fw-semibold fs-12 border-0">{{ $user->initials() }}</span>
                                        <div class="min-w-0">
                                            <div class="fw-semibold">
                                                {{ $user->name }}
                                                @if ($user->is($this->me)) <span class="badge bg-info-subtle text-info ms-1">You</span> @endif
                                            </div>
                                            @if ($user->phone)<div class="fs-12 text-muted">{{ $user->phone }}</div>@endif
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Signs in with">
                                    <div class="cell-stack">
                                        <div>{{ $user->email ?? '—' }}</div>
                                        @if ($user->username)<div class="fs-12 text-muted sfms-mono">{{ $user->username }}</div>@endif
                                    </div>
                                </td>
                                <td data-label="Role">
                                    <span class="badge {{ $user->role?->is_locked ? 'bg-primary-subtle text-primary' : 'bg-light text-body' }}">{{ $user->role?->name ?? 'No role' }}</span>
                                </td>
                                <td data-label="Last sign-in" class="fs-13">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                <td data-label="Status">
                                    <span class="badge {{ $user->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span>
                                </td>
                                <td class="text-end td-actions">
                                    @if ($manageable)
                                        <div class="hstack gap-2 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light" wire:click="openEdit({{ $user->id }})"><i class="ri-pencil-line"></i> Edit</button>
                                            <button type="button" class="btn btn-sm btn-light" wire:click="openResetPassword({{ $user->id }})"><i class="ri-lock-password-line"></i> Password</button>
                                            @unless ($user->is($this->me))
                                                <button type="button" class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                                        wire:click="toggleActive({{ $user->id }})"
                                                        wire:confirm="{{ $user->is_active ? 'Disable '.$user->name.'? They will be signed out.' : 'Allow '.$user->name.' to sign in again?' }}">
                                                    {{ $user->is_active ? 'Disable' : 'Enable' }}
                                                </button>
                                            @endunless
                                        </div>
                                    @else
                                        <span class="fs-12 text-muted">Owner only</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit user' : 'Add user'" size="lg">
        <form wire:submit="save" novalidate>
            <div class="modal-body">
                <div class="row g-3">
                    <x-input class="col-md-6" model="name" label="Name" required autocomplete="off" />
                    <x-select class="col-md-6" model="roleId" label="Role" required
                              :options="$this->assignableRoles->pluck('name', 'id')->all()"
                              :disabled="$editingId && $editingId === $this->me->id" />
                    <x-input class="col-md-6" model="email" label="Email" type="email" autocomplete="off" />
                    <x-input class="col-md-6" model="username" label="Or username" autocomplete="off" autocapitalize="none" hint="Staff can sign in with either." />
                    <x-input class="col-md-6" model="phone" label="Phone" type="tel" autocomplete="off" />
                    @unless ($editingId)
                        <div class="col-md-6">
                            <x-password-input model="password" id="staff-password" autocomplete="new-password" generate="generatePassword" :required="true" />
                        </div>
                    @endunless
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save changes' : 'Add user' }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal wire:model="showPasswordModal" title="Reset password">
        <form wire:submit="resetPassword" novalidate>
            <div class="modal-body">
                <p class="text-muted">New password for <strong>{{ $this->users->firstWhere('id', $passwordUserId)?->name }}</strong>.</p>
                <x-password-input model="newPassword" id="staff-new-password" label="New password" autocomplete="new-password" generate="generateNewPassword" :required="true" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="resetPassword">Reset password</button>
            </div>
        </form>
    </x-modal>
</div>
