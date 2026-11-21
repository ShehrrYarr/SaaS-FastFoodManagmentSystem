@props([
    'model',
    'label' => null,
    'rows' => 3,
    'required' => false,
    'hint' => null,
    'modifier' => null,
    'id' => null,
])

@php
    $id = $id ?? 'f-'.str_replace(['.', '_'], '-', $model);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif

    <textarea id="{{ $id }}" rows="{{ $rows }}"
              {{ 'wire:model'.($modifier ? '.'.$modifier : '') }}="{{ $model }}"
              {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($model)]) }}></textarea>

    @error($model)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @else
        @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
    @enderror
</div>
