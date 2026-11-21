<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::admin')] class extends Component
{
    public Shop $shop;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    // Details
    public string $name = '';
    public string $orderPrefix = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $currency = 'PKR';
    public string $currencySymbol = 'Rs';
    public string $timezone = 'Asia/Karachi';
    public bool $inventoryTrackingEnabled = false;
    public string $adminNotes = '';

    // Subscription
    public string $subscriptionEndsAt = '';

    // Suspend
    public bool $showSuspendModal = false;
    public string $suspendReason = '';

    // Add user
    public bool $showUserModal = false;
    public string $userName = '';
    public string $userEmail = '';
    public string $userUsername = '';
    public string $userPhone = '';
    public string $userPassword = '';
    public ?int $userRoleId = null;

    // Reset password
    public bool $showPasswordModal = false;
    public ?int $passwordUserId = null;
    public string $newPassword = '';

    /** Login details to hand over once (after creating a shop/user or resetting a password). */
    public ?array $handover = null;

    public function mount(Shop $shop): void
    {
        $this->shop = $shop;
        $this->fillDetails();

        if ($login = session('new_shop_login')) {
            $this->handover = $login + ['name' => $shop->owner?->name ?? 'Owner'];
        }
    }

    public function render()
    {
        return $this->view()->title($this->shop->name);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['overview', 'users', 'access'], true) ? $tab : 'overview';
    }

    // ---------------------------------------------------------------- details

    protected function fillDetails(): void
    {
        $shop = $this->shop;

        $this->name = $shop->name;
        $this->orderPrefix = $shop->order_prefix;
        $this->phone = (string) $shop->phone;
        $this->email = (string) $shop->email;
        $this->address = (string) $shop->address;
        $this->currency = $shop->currency;
        $this->currencySymbol = $shop->currency_symbol;
        $this->timezone = $shop->timezone;
        $this->inventoryTrackingEnabled = $shop->inventory_tracking_enabled;
        $this->adminNotes = (string) $shop->admin_notes;
        $this->subscriptionEndsAt = $shop->subscription_ends_at?->toDateString() ?? '';
    }

    public function updatedOrderPrefix(string $value): void
    {
        $this->orderPrefix = Str::upper(preg_replace('/[^a-z0-9]/i', '', $value));
    }

    public function saveDetails(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'orderPrefix' => ['required', 'string', 'regex:'.Shop::ORDER_PREFIX_RULE],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'currencySymbol' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'timezone:all'],
            'inventoryTrackingEnabled' => ['boolean'],
            'adminNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'orderPrefix.regex' => 'Use 2-10 capital letters or numbers, e.g. BLG.',
        ], [
            'orderPrefix' => 'order number prefix',
            'currencySymbol' => 'currency symbol',
            'adminNotes' => 'notes',
        ]);

        $this->shop->update([
            'name' => trim($this->name),
            'order_prefix' => $this->orderPrefix,
            'phone' => $this->phone ?: null,
            'email' => $this->email ?: null,
            'address' => $this->address ?: null,
            'currency' => Str::upper($this->currency),
            'currency_symbol' => $this->currencySymbol,
            'timezone' => $this->timezone,
            'inventory_tracking_enabled' => $this->inventoryTrackingEnabled,
            'admin_notes' => $this->adminNotes ?: null,
        ]);

        $this->dispatch('toast', type: 'success', message: 'Shop details saved.');
    }

    // ---------------------------------------------------------------- subscription

    public function extend(int $months): void
    {
        $shop = $this->shop;
        $start = $shop->subscription_ends_at && ! $shop->isSubscriptionExpired()
            ? Carbon::parse($shop->subscription_ends_at->toDateString())
            : Carbon::now(Shop::DEFAULT_TIMEZONE)->startOfDay();

        $shop->update(['subscription_ends_at' => $start->addMonthsNoOverflow($months)->toDateString()]);
        $this->subscriptionEndsAt = $shop->subscription_ends_at->toDateString();

        $this->dispatch('toast', type: 'success', message: 'Paid until '.$shop->subscription_ends_at->format('d M Y').'.');
    }

    public function saveSubscription(): void
    {
        $this->validate(['subscriptionEndsAt' => ['nullable', 'date']], [], ['subscriptionEndsAt' => 'paid-until date']);

        $this->shop->update(['subscription_ends_at' => $this->subscriptionEndsAt ?: null]);

        $this->dispatch('toast', type: 'success', message: $this->subscriptionEndsAt
            ? 'Paid until '.$this->shop->subscription_ends_at->format('d M Y').'.'
            : 'Subscription end date removed.');
    }

    // ---------------------------------------------------------------- suspend / activate

    public function openSuspend(): void
    {
        $this->reset('suspendReason');
        $this->resetValidation();
        $this->showSuspendModal = true;
    }

    public function suspend(): void
    {
        $this->validate(['suspendReason' => ['required', 'string', 'max:255']], [], ['suspendReason' => 'reason']);

        $this->shop->update([
            'is_active' => false,
            'suspended_reason' => trim($this->suspendReason),
            'suspended_at' => now(),
        ]);

        $this->showSuspendModal = false;
        $this->dispatch('toast', type: 'warning', message: $this->shop->name.' is suspended. Staff are signed out on their next action.');
    }

    public function activate(): void
    {
        $this->shop->update(['is_active' => true, 'suspended_reason' => null, 'suspended_at' => null]);

        $this->dispatch('toast', type: 'success', message: $this->shop->name.' is active again.');
    }

    // ---------------------------------------------------------------- users

    #[Computed]
    public function users()
    {
        return User::ofShop($this->shop)->with('role')->orderByDesc('is_active')->orderBy('name')->get();
    }

    #[Computed]
    public function roles()
    {
        return $this->shop->roles()->orderBy('id')->get();
    }

    public function openCreateUser(): void
    {
        $this->reset('userName', 'userEmail', 'userUsername', 'userPhone');
        $this->userPassword = Str::password(10, symbols: false);
        $this->userRoleId = $this->roles->firstWhere('name', 'Cashier')?->id ?? $this->roles->first()?->id;
        $this->resetValidation();
        $this->showUserModal = true;
    }

    public function generateUserPassword(): void
    {
        $this->userPassword = Str::password(10, symbols: false);
    }

    public function createUser(): void
    {
        $this->userEmail = Str::lower(trim($this->userEmail));
        $this->userUsername = Str::lower(trim($this->userUsername));

        $this->validate([
            'userName' => ['required', 'string', 'max:120'],
            'userEmail' => ['nullable', 'required_without:userUsername', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $this->shop->id)],
            'userUsername' => ['nullable', 'required_without:userEmail', 'alpha_dash', 'min:3', 'max:60',
                Rule::unique('users', 'username')->where('tenant_id', $this->shop->id)],
            'userPhone' => ['nullable', 'string', 'max:30'],
            'userRoleId' => ['required', Rule::exists('roles', 'id')->where('tenant_id', $this->shop->id)],
            'userPassword' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'userEmail.required_without' => 'Enter an email or a username.',
            'userUsername.required_without' => 'Enter an email or a username.',
            'userEmail.unique' => 'Someone in this shop already uses this email.',
            'userUsername.unique' => 'Someone in this shop already uses this username.',
        ], [
            'userName' => 'name', 'userEmail' => 'email', 'userUsername' => 'username',
            'userPhone' => 'phone', 'userRoleId' => 'role', 'userPassword' => 'password',
        ]);

        $user = User::create([
            'tenant_id' => $this->shop->id,
            'role_id' => $this->userRoleId,
            'name' => trim($this->userName),
            'email' => $this->userEmail ?: null,
            'username' => $this->userUsername ?: null,
            'phone' => $this->userPhone ?: null,
            'password' => $this->userPassword,
        ]);

        $this->handover = [
            'name' => $user->name,
            'url' => $this->shop->loginUrl(),
            'login' => $user->loginName(),
            'password' => $this->userPassword,
        ];

        $this->showUserModal = false;
        unset($this->users);

        $this->dispatch('toast', type: 'success', message: $user->name.' can now sign in.');
    }

    public function openResetPassword(int $userId): void
    {
        $user = $this->findUser($userId);

        $this->passwordUserId = $user->id;
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

        $user = $this->findUser($this->passwordUserId);
        $user->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();

        $this->handover = [
            'name' => $user->name,
            'url' => $this->shop->loginUrl(),
            'login' => $user->loginName(),
            'password' => $this->newPassword,
        ];

        $this->showPasswordModal = false;
        $this->dispatch('toast', type: 'success', message: 'Password reset for '.$user->name.'.');
    }

    public function toggleUserActive(int $userId): void
    {
        $user = $this->findUser($userId);

        if ($user->is_active && $user->isOwner()) {
            $activeOwners = User::ofShop($this->shop)
                ->where('is_active', true)
                ->whereIn('role_id', $this->roles->where('is_locked', true)->pluck('id'))
                ->count();

            if ($activeOwners <= 1) {
                $this->dispatch('toast', type: 'error', message: 'This is the only active owner. Add another owner first.');

                return;
            }
        }

        $user->update(['is_active' => ! $user->is_active]);
        unset($this->users);

        $this->dispatch('toast', type: 'success', message: $user->name.($user->is_active ? ' can sign in again.' : ' can no longer sign in.'));
    }

    public function dismissHandover(): void
    {
        $this->handover = null;
    }

    private function findUser(?int $id): User
    {
        return User::ofShop($this->shop)->findOrFail($id);
    }
};
?>

<div>
    @php($badge = $shop->statusBadge())

    <x-page-header :title="$shop->name" :breadcrumbs="['Dashboard' => route('admin.dashboard'), 'Shops' => route('admin.shops.index'), $shop->name => null]">
        <x-slot:actions>
            <a href="{{ $shop->loginUrl() }}" target="_blank" rel="noopener" class="btn btn-light btn-touch">
                <i class="ri-external-link-line"></i>Sign-in page
            </a>
            @if ($shop->is_active)
                <button type="button" class="btn btn-outline-danger btn-touch" wire:click="openSuspend">
                    <i class="ri-forbid-2-line"></i>Suspend
                </button>
            @else
                <button type="button" class="btn btn-success btn-touch" wire:click="activate" wire:loading.attr="disabled">
                    <i class="ri-play-circle-line"></i>Activate
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- One-time login details --}}
    @if ($handover)
        @php($copyText = "Sign-in page: {$handover['url']}\nLogin: {$handover['login']}\nPassword: {$handover['password']}")
        <div class="alert alert-success sfms-banner" role="alert">
            <div class="hstack gap-3 align-items-start">
                <i class="ri-key-2-line fs-20 flex-shrink-0"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold mb-1">Login details for {{ $handover['name'] }}</div>
                    <div class="sfms-mono fs-13 text-break">{{ $handover['url'] }}</div>
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

    {{-- Summary --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-kpi label="Status" :value="$badge['label']" icon="ri-pulse-line"
                   :tone="match ($shop->status()) { 'suspended' => 'danger', 'expired' => 'secondary', 'expiring' => 'warning', default => 'success' }"
                   :hint="$shop->is_active ? 'Staff can sign in' : ($shop->suspended_reason ?: 'Suspended')" />
        </div>
        <div class="col-6 col-xl-3">
            @php($days = $shop->daysUntilExpiry())
            <x-kpi label="Paid until" :value="$shop->subscription_ends_at?->format('d M Y') ?? 'No end date'" icon="ri-calendar-check-line" tone="primary"
                   :hint="$days === null ? null : ($days < 0 ? 'Ended '.abs($days).' days ago' : ($days === 0 ? 'Ends today' : $days.' days left'))" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Staff users" :value="$this->users->where('is_active', true)->count()" icon="ri-team-line" tone="info"
                   :hint="$this->users->count().' in total'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Web address" :value="'/'.$shop->prefix" icon="ri-link" tone="secondary" :hint="'Orders '.\App\Models\ShopSequence::format($shop->order_prefix, 1)" />
        </div>
    </div>

    {{-- Tabs --}}
    <div class="sfms-filter-tabs mb-3" role="tablist">
        @foreach (['overview' => ['Details', 'ri-store-2-line'], 'users' => ['Staff users', 'ri-team-line'], 'access' => ['Subscription & access', 'ri-shield-check-line']] as $key => [$label, $icon])
            <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    class="btn btn-touch {{ $tab === $key ? 'btn-primary' : 'btn-light' }}">
                <i class="{{ $icon }}"></i>{{ $label }}
                @if ($key === 'users')
                    <span class="badge rounded-pill ms-1 {{ $tab === $key ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary' }}">{{ $this->users->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ------------------------------------------------ details --}}
    @if ($tab === 'overview')
        <form wire:submit="saveDetails" class="card mb-0" novalidate>
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label" for="name">Shop name<span class="text-danger ms-1">*</span></label>
                        <input type="text" id="name" wire:model="name" class="form-control form-control-touch @error('name') is-invalid @enderror">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="prefix">Shop prefix</label>
                        <input type="text" id="prefix" value="{{ $shop->prefix }}" class="form-control form-control-touch sfms-mono" disabled>
                        <div class="form-text">Locked: it is the shop's web address.</div>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="orderPrefix">Order prefix<span class="text-danger ms-1">*</span></label>
                        <input type="text" id="orderPrefix" wire:model.blur="orderPrefix" maxlength="10"
                               class="form-control form-control-touch sfms-mono text-uppercase @error('orderPrefix') is-invalid @enderror">
                        @error('orderPrefix') <div class="invalid-feedback">{{ $message }}</div> @else <div class="form-text">Applies to new orders.</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="tel" id="phone" wire:model="phone" class="form-control form-control-touch @error('phone') is-invalid @enderror">
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Shop email</label>
                        <input type="email" id="email" wire:model="email" class="form-control form-control-touch @error('email') is-invalid @enderror">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">Address</label>
                        <input type="text" id="address" wire:model="address" class="form-control form-control-touch @error('address') is-invalid @enderror">
                        @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="currency">Currency</label>
                        <input type="text" id="currency" wire:model="currency" maxlength="10" class="form-control form-control-touch text-uppercase @error('currency') is-invalid @enderror">
                        @error('currency') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="currencySymbol">Symbol</label>
                        <input type="text" id="currencySymbol" wire:model="currencySymbol" maxlength="10" class="form-control form-control-touch @error('currencySymbol') is-invalid @enderror">
                        @error('currencySymbol') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="timezone">Timezone</label>
                        <select id="timezone" wire:model="timezone" class="form-select form-select-touch @error('timezone') is-invalid @enderror">
                            @foreach (['Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kolkata', 'Europe/London', 'UTC'] as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </select>
                        @error('timezone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <div class="border rounded-3 p-3">
                            <div class="form-check form-switch d-flex gap-3 align-items-start ps-0 mb-0">
                                <input class="form-check-input ms-0 mt-1 flex-shrink-0" type="checkbox" role="switch" id="inventoryTrackingEnabled"
                                       wire:model="inventoryTrackingEnabled" style="width: 2.5rem; height: 1.3rem;">
                                <label class="form-check-label" for="inventoryTrackingEnabled">
                                    <span class="fw-semibold d-block">Ingredient inventory tracking</span>
                                    <span class="text-muted fs-13">When on, the shop manages ingredients and recipes, and stock is deducted as orders go to the kitchen.</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="adminNotes">Internal notes</label>
                        <textarea id="adminNotes" wire:model="adminNotes" rows="3" class="form-control @error('adminNotes') is-invalid @enderror"
                                  placeholder="Only visible to Super Admins, e.g. billing contact or plan details"></textarea>
                        @error('adminNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer sfms-sticky-actions hstack justify-content-end">
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="saveDetails">
                    <span wire:loading.remove wire:target="saveDetails"><i class="ri-save-3-line"></i> Save details</span>
                    <span wire:loading wire:target="saveDetails" class="spinner-border spinner-border-sm" role="status"></span>
                </button>
            </div>
        </form>
    @endif

    {{-- ------------------------------------------------ users --}}
    @if ($tab === 'users')
        <div class="card mb-0">
            <div class="card-header hstack justify-content-between gap-2 flex-wrap">
                <div>
                    <h5 class="card-title mb-0">Staff users</h5>
                    <span class="text-muted fs-13">People who can sign in at <span class="sfms-mono">/{{ $shop->prefix }}/login</span></span>
                </div>
                <button type="button" class="btn btn-primary btn-touch" wire:click="openCreateUser">
                    <i class="ri-user-add-line"></i>Add user
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Signs in with</th>
                                <th>Role</th>
                                <th>Last sign-in</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->users as $user)
                                <tr wire:key="user-{{ $user->id }}" class="{{ $user->is_active ? '' : 'opacity-75' }}">
                                    <td class="td-primary">
                                        <div class="hstack gap-3">
                                            <span class="avatar avatar-sm avatar-title rounded-circle bg-primary-subtle text-primary fw-semibold fs-12 border-0">{{ $user->initials() }}</span>
                                            <div class="min-w-0">
                                                <div class="fw-semibold">{{ $user->name }}</div>
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
                                        <span class="badge {{ $user->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                            {{ $user->is_active ? 'Active' : 'Disabled' }}
                                        </span>
                                    </td>
                                    <td class="text-end td-actions">
                                        <div class="hstack gap-2 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light" wire:click="openResetPassword({{ $user->id }})">
                                                <i class="ri-lock-password-line"></i> Reset password
                                            </button>
                                            <button type="button" class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                                    wire:click="toggleUserActive({{ $user->id }})"
                                                    wire:confirm="{{ $user->is_active ? 'Disable '.$user->name.'? They will be signed out.' : 'Allow '.$user->name.' to sign in again?' }}">
                                                {{ $user->is_active ? 'Disable' : 'Enable' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------ subscription & access --}}
    @if ($tab === 'access')
        <div class="row g-3">
            <div class="col-lg-6">
                <form wire:submit="saveSubscription" class="card mb-0 h-100" novalidate>
                    <div class="card-header">
                        <h5 class="card-title mb-0">Subscription</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted fs-13">Staff can sign in until the end of this day. A warning shows {{ \App\Models\Shop::EXPIRY_WARNING_DAYS }} days before.</p>
                        <span class="form-label d-block">Extend from current end date</span>
                        <div class="hstack gap-2 flex-wrap mb-4">
                            @foreach ([1 => '+1 month', 3 => '+3 months', 6 => '+6 months', 12 => '+1 year'] as $months => $label)
                                <button type="button" class="btn btn-light btn-touch" wire:click="extend({{ $months }})" wire:loading.attr="disabled">{{ $label }}</button>
                            @endforeach
                        </div>
                        <label class="form-label" for="subscriptionEndsAt">Or set paid-until date</label>
                        <input type="date" id="subscriptionEndsAt" wire:model="subscriptionEndsAt"
                               class="form-control form-control-touch @error('subscriptionEndsAt') is-invalid @enderror">
                        @error('subscriptionEndsAt') <div class="invalid-feedback">{{ $message }}</div> @else <div class="form-text">Leave empty for no end date.</div> @enderror
                    </div>
                    <div class="card-footer hstack justify-content-end">
                        <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="saveSubscription">Save date</button>
                    </div>
                </form>
            </div>
            <div class="col-lg-6">
                <div class="card mb-0 h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Access</h5>
                    </div>
                    <div class="card-body">
                        @if ($shop->is_active)
                            <div class="hstack gap-3 align-items-start mb-4">
                                <span class="avatar avatar-sm avatar-title rounded-circle bg-success-subtle text-success border-0"><i class="ri-checkbox-circle-line"></i></span>
                                <div>
                                    <div class="fw-semibold">Active</div>
                                    <div class="text-muted fs-13">Suspending signs every staff member out on their next action and blocks new sign-ins until you activate the shop again. No data is deleted.</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-touch" wire:click="openSuspend">
                                <i class="ri-forbid-2-line"></i>Suspend shop
                            </button>
                        @else
                            <div class="hstack gap-3 align-items-start mb-4">
                                <span class="avatar avatar-sm avatar-title rounded-circle bg-danger-subtle text-danger border-0"><i class="ri-forbid-2-line"></i></span>
                                <div>
                                    <div class="fw-semibold">Suspended {{ $shop->suspended_at?->diffForHumans() }}</div>
                                    <div class="text-muted fs-13">Reason: {{ $shop->suspended_reason ?: 'not given' }}</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-success btn-touch" wire:click="activate" wire:loading.attr="disabled">
                                <i class="ri-play-circle-line"></i>Activate shop
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------ modals --}}
    <x-modal wire:model="showSuspendModal" title="Suspend {{ $shop->name }}">
        <form wire:submit="suspend" novalidate>
            <div class="modal-body">
                <p class="text-muted">Staff will be signed out and see this reason on the sign-in page.</p>
                <label class="form-label" for="suspendReason">Reason<span class="text-danger ms-1">*</span></label>
                <input type="text" id="suspendReason" wire:model="suspendReason" placeholder="e.g. Payment overdue"
                       class="form-control form-control-touch @error('suspendReason') is-invalid @enderror">
                @error('suspendReason') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-touch" wire:loading.attr="disabled" wire:target="suspend">Suspend shop</button>
            </div>
        </form>
    </x-modal>

    <x-modal wire:model="showUserModal" title="Add staff user" size="lg">
        <form wire:submit="createUser" novalidate>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="userName">Name<span class="text-danger ms-1">*</span></label>
                        <input type="text" id="userName" wire:model="userName" autocomplete="off" class="form-control form-control-touch @error('userName') is-invalid @enderror">
                        @error('userName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="userRoleId">Role<span class="text-danger ms-1">*</span></label>
                        <select id="userRoleId" wire:model="userRoleId" class="form-select form-select-touch @error('userRoleId') is-invalid @enderror">
                            @foreach ($this->roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('userRoleId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="userEmail">Email</label>
                        <input type="email" id="userEmail" wire:model="userEmail" autocomplete="off" class="form-control form-control-touch @error('userEmail') is-invalid @enderror">
                        @error('userEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="userUsername">Or username</label>
                        <input type="text" id="userUsername" wire:model="userUsername" autocomplete="off" autocapitalize="none" class="form-control form-control-touch @error('userUsername') is-invalid @enderror">
                        @error('userUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="userPhone">Phone</label>
                        <input type="tel" id="userPhone" wire:model="userPhone" autocomplete="off" class="form-control form-control-touch @error('userPhone') is-invalid @enderror">
                        @error('userPhone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <x-password-input model="userPassword" id="userPassword" autocomplete="new-password" generate="generateUserPassword" :required="true" />
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="createUser">Add user</button>
            </div>
        </form>
    </x-modal>

    <x-modal wire:model="showPasswordModal" title="Reset password">
        <form wire:submit="resetPassword" novalidate>
            <div class="modal-body">
                <p class="text-muted">
                    New password for <strong>{{ $this->users->firstWhere('id', $passwordUserId)?->name }}</strong>.
                    "Keep me signed in" on their devices stops working.
                </p>
                <x-password-input model="newPassword" id="newPassword" label="New password" autocomplete="new-password" generate="generateNewPassword" :required="true" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="resetPassword">Reset password</button>
            </div>
        </form>
    </x-modal>
</div>
