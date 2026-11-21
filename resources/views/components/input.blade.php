{{--
    Labelled Herozi text input bound to a Livewire property.
    <x-input model="name" label="Name" required />
    <x-input model="price" label="Price" prepend="Rs" inputmode="decimal" modifier="live.debounce.400ms" />
--}}
@props([
    'model',
    'label' => null,
    'type' => 'text',
    'required' => false,
    'hint' => null,
    'modifier' => null,
    'prepend' => null,
    'append' => null,
    'id' => null,
])

@php
    $id = $id ?? 'f-'.str_replace(['.', '_'], '-', $model);
    $invalid = $errors->has($model);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif

    @if ($prepend || $append)
        <div class="input-group">
            @if ($prepend)<span class="input-group-text">{{ $prepend }}</span>@endif
    @endif

    <input type="{{ $type }}" id="{{ $id }}"
           {{ 'wire:model'.($modifier ? '.'.$modifier : '') }}="{{ $model }}"
           {{ $attributes->except('class')->class(['form-control form-control-touch', 'is-invalid' => $invalid]) }}>

    @if ($prepend || $append)
            @if ($append)<span class="input-group-text">{{ $append }}</span>@endif
        </div>
    @endif

    @error($model)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @else
        @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
    @enderror
</div>
