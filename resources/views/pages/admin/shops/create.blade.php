<?php

use App\Actions\Shops\CreateShop;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::admin')] #[Title('New shop')] class extends Component
{
    public const STEPS = [1 => 'Shop', 2 => 'Plan & features', 3 => 'Owner login', 4 => 'Review'];

    public int $step = 1;

    // Step 1: shop
    public string $name = '';
    public string $prefix = '';
    public string $orderPrefix = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public bool $prefixEdited = false;
    public bool $orderPrefixEdited = false;

    // Step 2: plan & features
    public string $subscriptionEndsAt = '';
    public bool $inventoryTrackingEnabled = false;

    // Step 3: owner
    public string $ownerName = '';
    public string $ownerEmail = '';
    public string $ownerUsername = '';
    public string $ownerPhone = '';
    public string $ownerPassword = '';

    public function mount(): void
    {
        $this->subscriptionEndsAt = Carbon::now(Shop::DEFAULT_TIMEZONE)->addMonth()->toDateString();
        $this->ownerPassword = $this->makePassword();
    }

    // ---------------------------------------------------------------- live helpers

    public function updatedName(string $value): void
    {
        if (! $this->prefixEdited) {
            $this->prefix = $this->uniquePrefix(Shop::suggestPrefix($value));
        }

        if (! $this->orderPrefixEdited) {
            $this->orderPrefix = Shop::suggestOrderPrefix($this->prefix);
        }
    }

    public function updatedPrefix(string $value): void
    {
        $this->prefixEdited = true;
        $this->prefix = Str::lower(preg_replace('/[^a-z0-9-]/i', '', str_replace(' ', '-', trim($value))));

        if (! $this->orderPrefixEdited) {
            $this->orderPrefix = Shop::suggestOrderPrefix($this->prefix);
        }
    }

    public function updatedOrderPrefix(string $value): void
    {
        $this->orderPrefixEdited = true;
        $this->orderPrefix = Str::upper(preg_replace('/[^a-z0-9]/i', '', $value));
    }

    public function generatePassword(): void
    {
        $this->ownerPassword = $this->makePassword();
    }

    public function extendBy(int $months): void
    {
        $this->subscriptionEndsAt = Carbon::now(Shop::DEFAULT_TIMEZONE)->addMonths($months)->toDateString();
    }

    /** available | taken | reserved | invalid | empty */
    #[Computed]
    public function prefixStatus(): string
    {
        if ($this->prefix === '') {
            return 'empty';
        }

        if (! preg_match(Shop::PREFIX_RULE, $this->prefix)) {
            return 'invalid';
        }

        if (Shop::isReservedPrefix($this->prefix)) {
            return 'reserved';
        }

        return Shop::query()->where('prefix', $this->prefix)->exists() ? 'taken' : 'available';
    }

    // ---------------------------------------------------------------- steps

    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step));
        $this->step = min($this->step + 1, 4);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function goTo(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
        }
    }

    public function save(CreateShop $createShop): void
    {
        foreach ([1, 2, 3] as $step) {
            try {
                $this->validate($this->rulesForStep($step));
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->step = $step;

                throw $e;
            }
        }

        $shop = $createShop->handle(
            [
                'name' => trim($this->name),
                'prefix' => $this->prefix,
                'order_prefix' => $this->orderPrefix,
                'phone' => $this->phone ?: null,
                'email' => $this->email ?: null,
                'address' => $this->address ?: null,
                'subscription_ends_at' => $this->subscriptionEndsAt ?: null,
                'inventory_tracking_enabled' => $this->inventoryTrackingEnabled,
            ],
            [
                'name' => trim($this->ownerName),
                'email' => $this->ownerEmail ?: null,
                'username' => $this->ownerUsername ?: null,
                'phone' => $this->ownerPhone ?: null,
                'password' => $this->ownerPassword,
            ],
        );

        // Shown once on the next page so the Super Admin can hand the login over.
        session()->flash('new_shop_login', [
            'url' => $shop->loginUrl(),
            'login' => $this->ownerEmail ?: $this->ownerUsername,
            'password' => $this->ownerPassword,
        ]);
        session()->flash('toast', ['type' => 'success', 'message' => $shop->name.' is ready.']);

        $this->redirectRoute('admin.shops.show', ['shop' => $shop->id], navigate: true);
    }

    // ---------------------------------------------------------------- rules

    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'name' => ['required', 'string', 'max:120'],
                'prefix' => [
                    'required', 'string', 'min:2', 'max:20', 'regex:'.Shop::PREFIX_RULE,
                    Rule::notIn(Shop::RESERVED_PREFIXES), 'not_regex:/^livewire/',
                    Rule::unique('shops', 'prefix'),
                ],
                'orderPrefix' => ['required', 'string', 'regex:'.Shop::ORDER_PREFIX_RULE],
                'phone' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
            ],
            2 => [
                'subscriptionEndsAt' => ['nullable', 'date'],
                'inventoryTrackingEnabled' => ['boolean'],
            ],
            3 => [
                'ownerName' => ['required', 'string', 'max:120'],
                'ownerEmail' => ['nullable', 'required_without:ownerUsername', 'email', 'max:255'],
                'ownerUsername' => ['nullable', 'required_without:ownerEmail', 'alpha_dash', 'min:3', 'max:60'],
                'ownerPhone' => ['nullable', 'string', 'max:30'],
                'ownerPassword' => ['required', 'string', 'min:8', 'max:100'],
            ],
            default => [],
        };
    }

    protected function validationAttributes(): array
    {
        return [
            'orderPrefix' => 'order number prefix',
            'subscriptionEndsAt' => 'paid-until date',
            'ownerName' => 'owner name',
            'ownerEmail' => 'owner email',
            'ownerUsername' => 'owner username',
            'ownerPhone' => 'owner phone',
            'ownerPassword' => 'password',
        ];
    }

    protected function messages(): array
    {
        return [
            'prefix.regex' => 'Use 2-20 lowercase letters, numbers or hyphens (not at the start or end).',
            'prefix.not_in' => 'This prefix is reserved by the system. Please choose another.',
            'prefix.not_regex' => 'This prefix is reserved by the system. Please choose another.',
            'prefix.unique' => 'Another shop already uses this prefix.',
            'orderPrefix.regex' => 'Use 2-10 capital letters or numbers, e.g. BLG.',
            'ownerEmail.required_without' => 'Enter an email or a username for the owner.',
            'ownerUsername.required_without' => 'Enter an email or a username for the owner.',
        ];
    }

    private function uniquePrefix(string $base): string
    {
        $base = $base !== '' ? $base : 'shop';
        $candidate = $base;
        $i = 2;

        while (Shop::isReservedPrefix($candidate) || Shop::query()->where('prefix', $candidate)->exists()) {
            $candidate = substr($base, 0, 18).$i++;
        }

        return $candidate;
    }

    private function makePassword(): string
    {
        return Str::password(10, symbols: false);
    }
};
?>

<div>
    <x-page-header title="New shop" :breadcrumbs="['Dashboard' => route('admin.dashboard'), 'Shops' => route('admin.shops.index'), 'New shop' => null]" />

    <div class="row justify-content-center">
        <div class="col-xxl-8 col-xl-10">
            <div class="sfms-steps" aria-label="Progress">
                @foreach ($this::STEPS as $number => $label)
                    <button type="button" wire:click="goTo({{ $number }})" @disabled($number > $step)
                            class="sfms-step {{ $number === $step ? 'is-active' : '' }} {{ $number < $step ? 'is-done' : '' }}"
                            aria-current="{{ $number === $step ? 'step' : 'false' }}">
                        <span class="sfms-step-num">
                            @if ($number < $step) <i class="ri-check-line"></i> @else {{ $number }} @endif
                        </span>
                        <span class="sfms-step-label">{{ $label }}</span>
                    </button>
                @endforeach
            </div>

            <form wire:submit="{{ $step < 4 ? 'next' : 'save' }}" class="card mb-0" novalidate>
                <div class="card-body p-4">
                    {{-- ------------------------------------------------ step 1 --}}
                    @if ($step === 1)
                        <h5 class="mb-1">Shop details</h5>
                        <p class="text-muted mb-4">The prefix becomes the shop's web address and the start of its order numbers.</p>

                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label" for="name">Shop name<span class="text-danger ms-1">*</span></label>
                                <input type="text" id="name" wire:model.live.debounce.400ms="name" placeholder="e.g. Burger Lab Gulberg"
                                       class="form-control form-control-touch @error('name') is-invalid @enderror" autofocus>
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-7">
                                <label class="form-label" for="prefix">Shop prefix (URL)<span class="text-danger ms-1">*</span></label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text bg-transparent text-muted sfms-mono">/</span>
                                    <input type="text" id="prefix" wire:model.live.debounce.400ms="prefix" placeholder="blg"
                                           autocapitalize="none" spellcheck="false" maxlength="20"
                                           class="form-control form-control-touch sfms-mono @error('prefix') is-invalid @enderror">
                                    <span class="input-group-text bg-transparent text-muted sfms-mono d-none d-sm-flex">/login</span>
                                    @error('prefix') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                @unless ($errors->has('prefix'))
                                    <div class="form-text">
                                        @switch($this->prefixStatus)
                                            @case('available') <span class="text-success"><i class="ri-checkbox-circle-line"></i> Available</span> @break
                                            @case('taken') <span class="text-danger"><i class="ri-close-circle-line"></i> Already used by another shop</span> @break
                                            @case('reserved') <span class="text-danger"><i class="ri-close-circle-line"></i> Reserved by the system</span> @break
                                            @case('invalid') <span class="text-danger"><i class="ri-close-circle-line"></i> 2-20 lowercase letters, numbers or hyphens</span> @break
                                            @default Lowercase letters, numbers and hyphens. Cannot be changed later.
                                        @endswitch
                                    </div>
                                @endunless
                            </div>

                            <div class="col-md-5">
                                <label class="form-label" for="orderPrefix">Order number prefix<span class="text-danger ms-1">*</span></label>
                                <input type="text" id="orderPrefix" wire:model.live.debounce.400ms="orderPrefix" placeholder="BLG" maxlength="10"
                                       class="form-control form-control-touch sfms-mono text-uppercase @error('orderPrefix') is-invalid @enderror">
                                @error('orderPrefix')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">Orders: <span class="sfms-mono">{{ \App\Models\ShopSequence::format($orderPrefix ?: 'XXX', 1) }}</span></div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="tel" id="phone" wire:model="phone" placeholder="042-35710000"
                                       class="form-control form-control-touch @error('phone') is-invalid @enderror">
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="email">Shop email</label>
                                <input type="email" id="email" wire:model="email" placeholder="contact@shop.pk"
                                       class="form-control form-control-touch @error('email') is-invalid @enderror">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="address">Address</label>
                                <input type="text" id="address" wire:model="address" placeholder="Street, area, city"
                                       class="form-control form-control-touch @error('address') is-invalid @enderror">
                                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    @endif

                    {{-- ------------------------------------------------ step 2 --}}
                    @if ($step === 2)
                        <h5 class="mb-1">Plan &amp; features</h5>
                        <p class="text-muted mb-4">Staff can sign in until the paid-until date. You can extend it any time.</p>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label" for="subscriptionEndsAt">Paid until</label>
                                <input type="date" id="subscriptionEndsAt" wire:model.live="subscriptionEndsAt"
                                       class="form-control form-control-touch @error('subscriptionEndsAt') is-invalid @enderror">
                                @error('subscriptionEndsAt')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">Leave empty for no end date.</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <span class="form-label d-block">Quick set</span>
                                <div class="hstack gap-2 flex-wrap">
                                    @foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '1 year'] as $months => $label)
                                        <button type="button" class="btn btn-light btn-touch" wire:click="extendBy({{ $months }})">{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="border rounded-3 p-3 p-sm-4">
                                    <div class="form-check form-switch d-flex gap-3 align-items-start ps-0 mb-0">
                                        <input class="form-check-input ms-0 mt-1 flex-shrink-0" type="checkbox" role="switch" id="inventoryTrackingEnabled"
                                               wire:model.live="inventoryTrackingEnabled" style="width: 2.5rem; height: 1.3rem;">
                                        <label class="form-check-label" for="inventoryTrackingEnabled">
                                            <span class="fw-semibold d-block">Ingredient inventory tracking</span>
                                            <span class="text-muted fs-13">Off: products sell without recipes or stock. On: ingredients, purchases and recipes deduct stock as orders go to the kitchen. The shop can change this later.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- ------------------------------------------------ step 3 --}}
                    @if ($step === 3)
                        <h5 class="mb-1">Owner login</h5>
                        <p class="text-muted mb-4">The owner gets full access and can add the rest of the staff.</p>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label" for="ownerName">Owner name<span class="text-danger ms-1">*</span></label>
                                <input type="text" id="ownerName" wire:model="ownerName" autocomplete="off"
                                       class="form-control form-control-touch @error('ownerName') is-invalid @enderror">
                                @error('ownerName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ownerPhone">Owner phone</label>
                                <input type="tel" id="ownerPhone" wire:model="ownerPhone" autocomplete="off"
                                       class="form-control form-control-touch @error('ownerPhone') is-invalid @enderror">
                                @error('ownerPhone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ownerEmail">Login email</label>
                                <input type="email" id="ownerEmail" wire:model="ownerEmail" autocomplete="off"
                                       class="form-control form-control-touch @error('ownerEmail') is-invalid @enderror">
                                @error('ownerEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ownerUsername">Or username</label>
                                <input type="text" id="ownerUsername" wire:model="ownerUsername" autocomplete="off" autocapitalize="none"
                                       class="form-control form-control-touch @error('ownerUsername') is-invalid @enderror">
                                @error('ownerUsername')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">Optional. Staff can sign in with either.</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <x-password-input model="ownerPassword" id="ownerPassword" label="Password" autocomplete="new-password"
                                                  generate="generatePassword" :required="true" />
                                <div class="form-text">At least 8 characters. You'll see it once more after saving.</div>
                            </div>
                        </div>
                    @endif

                    {{-- ------------------------------------------------ step 4 --}}
                    @if ($step === 4)
                        <h5 class="mb-1">Review</h5>
                        <p class="text-muted mb-4">Check everything, then create the shop.</p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="hstack justify-content-between mb-2">
                                        <span class="fw-semibold">Shop</span>
                                        <button type="button" class="btn btn-sm btn-link p-0" wire:click="goTo(1)">Edit</button>
                                    </div>
                                    <div>{{ $name }}</div>
                                    <div class="sfms-mono fs-13 text-muted">{{ url($prefix.'/login') }}</div>
                                    <div class="sfms-mono fs-13 text-muted">Orders {{ \App\Models\ShopSequence::format($orderPrefix, 1) }}</div>
                                    @if ($phone) <div class="fs-13 text-muted">{{ $phone }}</div> @endif
                                    @if ($address) <div class="fs-13 text-muted">{{ $address }}</div> @endif
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="hstack justify-content-between mb-2">
                                        <span class="fw-semibold">Plan &amp; features</span>
                                        <button type="button" class="btn btn-sm btn-link p-0" wire:click="goTo(2)">Edit</button>
                                    </div>
                                    <div>Paid until {{ $subscriptionEndsAt ? \Illuminate\Support\Carbon::parse($subscriptionEndsAt)->format('d M Y') : 'no end date' }}</div>
                                    <div class="fs-13 text-muted">Inventory tracking {{ $inventoryTrackingEnabled ? 'on' : 'off' }}</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="border rounded-3 p-3">
                                    <div class="hstack justify-content-between mb-2">
                                        <span class="fw-semibold">Owner login</span>
                                        <button type="button" class="btn btn-sm btn-link p-0" wire:click="goTo(3)">Edit</button>
                                    </div>
                                    <div>{{ $ownerName }} @if ($ownerPhone) <span class="text-muted fs-13">&middot; {{ $ownerPhone }}</span> @endif</div>
                                    <div class="fs-13 text-muted">Signs in with {{ collect([$ownerEmail, $ownerUsername])->filter()->implode(' or ') }}</div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="card-footer sfms-sticky-actions hstack gap-2 justify-content-between">
                    @if ($step > 1)
                        <button type="button" class="btn btn-light btn-touch" wire:click="back">
                            <i class="ri-arrow-left-line"></i>Back
                        </button>
                    @else
                        <a href="{{ route('admin.shops.index') }}" class="btn btn-light btn-touch" wire:navigate>Cancel</a>
                    @endif

                    <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="next,save">
                            @if ($step < 4) Continue <i class="ri-arrow-right-line"></i> @else <i class="ri-check-line"></i> Create shop @endif
                        </span>
                        <span wire:loading wire:target="next,save" class="spinner-border spinner-border-sm" role="status"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
