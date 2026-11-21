{{--
    Herozi/Bootstrap modal driven by a boolean Livewire property.
    <x-modal wire:model="showEditModal" title="Edit shop"> ... </x-modal>
    Full-screen on phones.
--}}
@props(['title' => null, 'size' => null, 'static' => false])

@php
    $model = $attributes->wire('model')->value();
@endphp

<div
    x-data="{ open: $wire.entangle('{{ $model }}'), modal: null }"
    x-init="
        modal = bootstrap.Modal.getOrCreateInstance($el);
        $watch('open', value => value ? modal.show() : modal.hide());
        $el.addEventListener('hidden.bs.modal', () => { if (open) open = false });
        if (open) modal.show();
    "
    wire:ignore.self
    class="modal fade"
    tabindex="-1"
    aria-hidden="true"
    @if ($static) data-bs-backdrop="static" data-bs-keyboard="false" @endif
    {{ $attributes->whereDoesntStartWith('wire:model') }}
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down {{ $size ? 'modal-'.$size : '' }}">
        <div class="modal-content">
            @if ($title)
                <div class="modal-header">
                    <h5 class="modal-title">{{ $title }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
