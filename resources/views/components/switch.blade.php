{{--
    Large, touch-friendly on/off switch with an optional description.
    <x-switch model="isAvailable" label="Available" description="Shown on the POS" />
    Pass :boxed="false" for a compact inline switch.
--}}
@props([
    'model',
    'label',
    'description' => null,
    'modifier' => null,
    'boxed' => true,
    'id' => null,
])

@php
    $id = $id ?? 'f-'.str_replace(['.', '_'], '-', $model);
@endphp

<div {{ $attributes->only('class')->class(['border rounded-3 p-3' => $boxed]) }}>
    <div class="form-check form-switch d-flex gap-3 align-items-start ps-0 mb-0">
        <input class="form-check-input ms-0 mt-1 flex-shrink-0 sfms-switch" type="checkbox" role="switch" id="{{ $id }}"
               {{ 'wire:model'.($modifier ? '.'.$modifier : '') }}="{{ $model }}"
               {{ $attributes->except('class') }}>
        <label class="form-check-label" for="{{ $id }}">
            <span class="fw-semibold d-block">{{ $label }}</span>
            @if ($description)
                <span class="text-muted fs-13">{{ $description }}</span>
            @endif
        </label>
    </div>
    @error($model)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
