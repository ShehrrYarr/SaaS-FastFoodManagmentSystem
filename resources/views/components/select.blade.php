{{--
    <x-select model="categoryId" label="Category" :options="[1 => 'Burgers']" placeholder="No category" />
--}}
@props([
    'model',
    'label' => null,
    'options' => [],
    'placeholder' => null,
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

    <select id="{{ $id }}"
            {{ 'wire:model'.($modifier ? '.'.$modifier : '') }}="{{ $model }}"
            {{ $attributes->except('class')->class(['form-select form-select-touch', 'is-invalid' => $errors->has($model)]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>

    @error($model)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @else
        @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
    @enderror
</div>
