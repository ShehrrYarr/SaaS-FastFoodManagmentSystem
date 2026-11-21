@props(['label', 'value', 'icon', 'tone' => 'primary', 'hint' => null])

<div {{ $attributes->merge(['class' => 'card h-100 sfms-kpi mb-0']) }}>
    <div class="card-body p-4">
        <div class="hstack gap-3 align-items-start">
            <div class="sfms-kpi-icon bg-{{ $tone }}-subtle text-{{ $tone }}">
                <i class="{{ $icon }}"></i>
            </div>
            <div class="min-w-0">
                <div class="text-muted fs-13 mb-1">{{ $label }}</div>
                <div class="sfms-kpi-value">{{ $value }}</div>
                @if ($hint)
                    <div class="fs-12 text-muted mt-1">{{ $hint }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
