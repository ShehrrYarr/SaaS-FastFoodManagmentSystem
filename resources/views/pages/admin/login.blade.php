<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Super Admin sign in')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if (Auth::guard('admin')->check()) {
            $this->redirectRoute('admin.dashboard');
        }
    }

    public function signIn(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::superAdmins()->where('email', Str::lower(trim($this->email)))->first();

        if (! $user || ! $user->is_active || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 60);
            $this->reset('password');

            throw ValidationException::withMessages([
                'email' => 'These details do not match an active Super Admin account.',
            ]);
        }

        RateLimiter::clear($key);

        Auth::guard('admin')->login($user, $this->remember);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Only honour an "intended" URL that belongs to the Super Admin area.
        // (Compared with full URLs, so it also works when the app lives under a path like /sfmg.)
        $intended = (string) session()->pull('url.intended');
        $area = url('super-admin');

        $this->redirect($intended === $area || str_starts_with($intended, $area.'/') ? $intended : route('admin.dashboard'));
    }
};
?>

<div class="sfms-auth">
    <div class="card sfms-auth-card">
        <div class="card-body p-4 p-sm-5">
            <div class="sfms-auth-logo"><i class="ri-shield-user-line fs-24"></i></div>
            <h4 class="text-center mb-1">Super Admin</h4>
            <p class="text-center text-muted mb-4">Sign in to manage shops and subscriptions.</p>

            <form wire:submit="signIn" novalidate>
                <div class="mb-4">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" wire:model="email" autocomplete="username" autofocus
                           class="form-control form-control-touch @error('email') is-invalid @enderror">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <x-password-input model="password" id="password" />
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="remember" wire:model="remember">
                    <label class="form-check-label" for="remember">Keep me signed in</label>
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-touch" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="signIn">Sign in</span>
                    <span wire:loading wire:target="signIn" class="spinner-border spinner-border-sm" role="status"></span>
                </button>
            </form>
        </div>
    </div>
</div>
