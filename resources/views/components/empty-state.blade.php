@props(['icon' => 'ri-inbox-line', 'title'])

<div class="text-center py-5 px-3">
    <div class="avatar avatar-lg avatar-title bg-light text-muted rounded-circle mx-auto mb-3 border-0">
        <i class="{{ $icon }} fs-24"></i>
    </div>
    <h6 class="mb-1">{{ $title }}</h6>
    <div class="text-muted fs-13">{{ $slot }}</div>
</div>
