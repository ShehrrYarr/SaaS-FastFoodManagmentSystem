<!DOCTYPE html>
<html lang="en" class="h-100" data-auth-page>
<head>
    @include('layouts.partials.head', ['title' => $title, 'auth' => true])
</head>
<body>
    <div class="sfms-auth">
        <div class="card sfms-auth-card">
            <div class="card-body p-4 p-sm-5 text-center">
                <div class="sfms-auth-logo {{ $tone ?? 'bg-danger' }}"><i class="{{ $icon }} fs-24"></i></div>
                <h4 class="mb-2">{{ $title }}</h4>
                <p class="text-muted mb-4">{{ $message }}</p>
                {{ $slot ?? '' }}
                @yield('actions')
            </div>
        </div>
    </div>
</body>
</html>
