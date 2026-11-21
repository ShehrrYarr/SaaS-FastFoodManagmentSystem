@php
    $shop = app(\App\Tenancy\TenantContext::class)->shop();
    $user = auth('shop')->user();
    $daysLeft = $shop->daysUntilExpiry();
@endphp
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'appTitle' => $shop->name])
</head>
<body>
    @persist('shop-header-'.$shop->id)
        @include('layouts.partials.header', [
            'user' => $user,
            'roleLabel' => $user?->role?->name ?? 'Staff',
            'logoutUrl' => route('shop.logout'),
            'context' => $shop->name,
            'contextSub' => '/'.$shop->prefix,
        ])
    @endpersist

    @persist('shop-sidebar-'.$shop->id)
        @include('layouts.partials.sidebar', [
            'menu' => \App\Support\Navigation::shop($shop, $user),
            'homeUrl' => route('shop.dashboard'),
            'brandMark' => $shop->initials(),
            'brandText' => $shop->name,
        ])
    @endpersist

    <main class="app-wrapper">
        <div class="app-container">
            @if ($shop->status() === 'expiring')
                <div class="alert alert-warning hstack gap-3 sfms-banner" role="alert">
                    <i class="ri-time-line fs-20 flex-shrink-0"></i>
                    <div>
                        Your subscription ends
                        <strong>{{ $daysLeft === 0 ? 'today' : 'in '.$daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft) }}</strong>
                        ({{ $shop->subscription_ends_at->format('d M Y') }}). Please contact support to renew and avoid interruption.
                    </div>
                </div>
            @endif

            {{ $slot }}
        </div>
    </main>

    @include('layouts.partials.footer')
    @include('layouts.partials.flash')
</body>
</html>
