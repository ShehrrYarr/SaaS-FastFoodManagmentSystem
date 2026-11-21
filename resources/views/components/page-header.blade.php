@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

<div class="sfms-page-header">
    <div class="min-w-0">
        <h4 class="sfms-page-title text-truncate">{{ $title }}</h4>
        @if ($breadcrumbs)
            <nav aria-label="Breadcrumb">
                <ol class="breadcrumb breadcrumb-arrow mb-0">
                    @foreach ($breadcrumbs as $label => $url)
                        @if ($loop->last || ! $url)
                            <li class="breadcrumb-item active" aria-current="page">{{ $label }}</li>
                        @else
                            <li class="breadcrumb-item"><a href="{{ $url }}" wire:navigate>{{ $label }}</a></li>
                        @endif
                    @endforeach
                </ol>
            </nav>
        @elseif ($subtitle)
            <p class="text-muted mb-0">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="sfms-page-actions">{{ $actions }}</div>
    @endisset
</div>
