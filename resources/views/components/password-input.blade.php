@props(['model', 'id', 'label' => 'Password', 'autocomplete' => 'current-password', 'generate' => null, 'required' => false])

<div x-data="{ show: false }">
    <label class="form-label" for="{{ $id }}">
        {{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif
    </label>
    <div class="input-group has-validation">
        <input type="password"
               x-bind:type="show ? 'text' : 'password'"
               id="{{ $id }}"
               wire:model="{{ $model }}"
               autocomplete="{{ $autocomplete }}"
               {{ $attributes->class(['form-control form-control-touch', 'is-invalid' => $errors->has($model)]) }}>
        <button type="button" class="btn btn-light border" x-on:click="show = !show"
                x-bind:aria-label="show ? 'Hide password' : 'Show password'">
            <i x-bind:class="show ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
        </button>
        @if ($generate)
            <button type="button" class="btn btn-light border" wire:click="{{ $generate }}" x-on:click="show = true"
                    title="Generate a password" aria-label="Generate a password">
                <i class="ri-magic-line"></i>
            </button>
        @endif
        @error($model)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
