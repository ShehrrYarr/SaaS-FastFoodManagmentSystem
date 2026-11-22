{{--
    Shows whether this screen updates live (sfms-live.js sets <html data-sfms-live="on">).
    <x-live-status />
--}}
<span {{ $attributes->merge(['class' => 'sfms-live-status']) }} role="status">
    <span class="sfms-live-on" title="Changes from other tills appear instantly"><span class="sfms-live-dot" aria-hidden="true"></span>Live</span>
    <span class="sfms-live-off" title="Live updates are not connected; this screen refreshes every minute"><i class="ri-refresh-line" aria-hidden="true"></i>Every minute</span>
</span>
