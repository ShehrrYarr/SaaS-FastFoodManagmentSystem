@php
    $user = auth('admin')->user();
@endphp
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'appTitle' => 'SFMS Super Admin'])
</head>
<body>
    @persist('admin-header')
        @include('layouts.partials.header', [
            'user' => $user,
            'roleLabel' => 'Super Admin',
            'logoutUrl' => route('admin.logout'),
            'context' => 'SFMS Control Panel',
            'contextSub' => 'Super Admin',
        ])
    @endpersist

    @persist('admin-sidebar')
        @include('layouts.partials.sidebar', [
            'menu' => \App\Support\Navigation::admin(),
            'homeUrl' => route('admin.dashboard'),
            'brandMark' => 'SF',
            'brandText' => 'SFMS Admin',
        ])
    @endpersist

    <main class="app-wrapper">
        <div class="app-container">
            {{ $slot }}
        </div>
    </main>

    @include('layouts.partials.footer')
    @include('layouts.partials.flash')
</body>
</html>
