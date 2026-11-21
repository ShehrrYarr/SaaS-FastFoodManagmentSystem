<!DOCTYPE html>
<html lang="en" class="h-100" data-auth-page>
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'auth' => true, 'appTitle' => isset($currentShop) ? $currentShop->name : config('app.name')])
</head>
<body>
    {{ $slot }}

    @include('layouts.partials.flash')
</body>
</html>
