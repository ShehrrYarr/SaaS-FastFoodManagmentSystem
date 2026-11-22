<?php

use App\Models\Shop;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Sign in')] class extends Component
{
    public string $login = '';

    public string $password = '';

    public bool $remember = false;

    #[Computed]
    public function shop(): Shop
    {
        return app(TenantContext::class)->shop();
    }

    public function mount(): void
    {
        $user = Auth::guard('shop')->user();

        if ($user && (int) $user->tenant_id === $this->shop->id && $user->is_active && $this->shop->isAccessible()) {
            $this->redirectRoute('shop.dashboard');
        }
    }

    public function signIn(): void
    {
        $shop = $this->shop;

        $this->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (! $shop->isAccessible()) {
            throw ValidationException::withMessages(['login' => $shop->blockedReason()]);
        }

        $key = 'shop-login:'.$shop->id.':'.Str::lower($this->login).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::ofShop($shop)->whereLogin($this->login)->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 60);
            $this->reset('password');

            throw ValidationException::withMessages([
                'login' => 'These details do not match an account at '.$shop->name.'.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => 'Your account has been disabled. Please ask your shop owner.',
            ]);
        }

        RateLimiter::clear($key);

        Auth::guard('shop')->login($user, $this->remember);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Only honour an "intended" URL inside this shop's workspace.
        // (Compared with full URLs, so it also works when the app lives under a path like /sfmg.)
        $intended = (string) session()->pull('url.intended');

        $this->redirect(str_starts_with($intended, url($shop->prefix).'/') ? $intended : route('shop.dashboard'));
    }
};
?>

<div class="sfms-auth">
    @php($blocked = $this->shop->blockedReason())
    <div class="card sfms-auth-card">
        <div class="card-body p-4 p-sm-5">
            <div class="sfms-auth-logo">
                @if ($this->shop->logo_path)
                    <img src="{{ Storage::url($this->shop->logo_path) }}" alt="{{ $this->shop->name }} logo">
                @else
                    {{ $this->shop->initials() }}
                @endif
            </div>
            <h4 class="text-center mb-1">{{ $this->shop->name }}</h4>
            <p class="text-center text-muted mb-4">Sign in to your shop workspace.</p>

            @if ($blocked)
                <div class="alert alert-danger hstack gap-2 align-items-start" role="alert">
                    <i class="ri-error-warning-line fs-18"></i>
                    <div>{{ $blocked }}</div>
                </div>
            @elseif (session('login_error'))
                <div class="alert alert-warning hstack gap-2 align-items-start" role="alert">
                    <i class="ri-information-line fs-18"></i>
                    <div>{{ session('login_error') }}</div>
                </div>
            @endif

            <form wire:submit="signIn" novalidate>
                <fieldset @disabled($blocked)>
                    <div class="mb-4">
                        <label class="form-label" for="login">Email or username</label>
                        <input type="text" id="login" wire:model="login" autocomplete="username" autocapitalize="none"
                               spellcheck="false" autofocus
                               class="form-control form-control-touch @error('login') is-invalid @enderror">
                        @error('login')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <x-password-input model="password" id="password" />
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="remember" wire:model="remember">
                        <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 btn-touch" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="signIn">Sign in</span>
                        <span wire:loading wire:target="signIn" class="spinner-border spinner-border-sm" role="status"></span>
                    </button>
                </fieldset>
            </form>

            <div class="text-center mt-4 fs-13 text-muted">
                Wrong shop? <a href="{{ route('find') }}" class="link-primary" wire:navigate>Enter another shop code</a>
            </div>
        </div>
    </div>
</div>
