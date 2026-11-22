{{--
    Live updates for shop pages: connection settings for sfms-live.js and the
    Echo/Pusher client. Nothing is loaded unless Reverb is configured.
--}}
@php
    $reverb = config('broadcasting.connections.reverb');
    $live = config('broadcasting.default') === 'reverb' && filled($reverb['key'] ?? null)
        ? [
            'shop' => $shop->id,
            'key' => $reverb['key'],
            'host' => $reverb['client']['host'] ?? request()->getHost(),
            'port' => $reverb['client']['port'] ?? 443,
            'scheme' => $reverb['client']['scheme'] ?? 'https',
            'auth' => url('/broadcasting/auth'),
        ]
        : null;
@endphp
@if ($live)
    <meta name="sfms-live" content="{{ json_encode($live) }}">
    <script src="{{ asset('assets/libs/pusher-js/pusher.min.js') }}"></script>
    <script src="{{ asset('assets/libs/laravel-echo/echo.iife.js') }}"></script>
@endif
