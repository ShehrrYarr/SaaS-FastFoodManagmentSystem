<?php

use App\Models\Shop;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Find your shop')] class extends Component
{
    public string $code = '';

    public function go(): void
    {
        $this->validate(['code' => ['required', 'string', 'max:40']]);

        $code = Str::lower(trim($this->code, " /\t\n"));
        $shop = Shop::query()->where('prefix', $code)->first();

        if (! $shop) {
            $this->addError('code', 'We could not find a shop with that code.');

            return;
        }

        $this->redirect($shop->loginUrl(), navigate: true);
    }
};
?>

<div class="sfms-auth">
    <div class="card sfms-auth-card">
        <div class="card-body p-4 p-sm-5">
            <div class="sfms-auth-logo"><i class="ri-store-2-line fs-24"></i></div>
            <h4 class="text-center mb-1">Welcome to SFMS</h4>
            <p class="text-center text-muted mb-4">Enter your shop code to go to its sign-in page.</p>

            <form wire:submit="go" novalidate>
                <label class="form-label" for="shop-code">Shop code</label>
                <div class="input-group has-validation mb-4">
                    <span class="input-group-text bg-transparent text-muted sfms-mono">{{ request()->getHttpHost() }}/</span>
                    <input type="text" id="shop-code" wire:model="code" placeholder="e.g. blg" autocapitalize="none"
                           autocomplete="off" spellcheck="false" autofocus
                           class="form-control form-control-touch sfms-mono @error('code') is-invalid @enderror">
                    @error('code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-touch" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="go">Continue</span>
                    <span wire:loading wire:target="go" class="spinner-border spinner-border-sm" role="status"></span>
                </button>
            </form>

            <div class="text-center mt-4 fs-13">
                <a href="{{ route('admin.login') }}" class="link-secondary">Super Admin sign in</a>
            </div>
        </div>
    </div>
</div>
