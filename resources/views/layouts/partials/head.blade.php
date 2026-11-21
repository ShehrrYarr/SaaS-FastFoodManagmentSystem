{{--
    Shared <head> for every page. Scripts live here (not at the end of <body>)
    so wire:navigate loads them once and keeps them across page swaps.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ $appTitle ?? config('app.name') }}</title>
<link rel="shortcut icon" href="{{ asset('assets/images/Favicon.png') }}">

<script src="{{ asset('assets/js/layout/'.(! empty($auth) ? 'layout-auth' : 'layout-default').'.js') }}"></script>
<script src="{{ asset('assets/js/layout/layout.js') }}"></script>

<link rel="stylesheet" href="{{ asset('assets/libs/simplebar/simplebar.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/icons.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}" id="bootstrap-style">
<link rel="stylesheet" href="{{ asset('assets/css/app.min.css') }}" id="app-style">
<link rel="stylesheet" href="{{ asset('assets/css/custom.min.css') }}" id="custom-style">
<link rel="stylesheet" href="{{ asset('assets/css/sfms.min.css') }}">

<script src="{{ asset('assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/libs/simplebar/simplebar.min.js') }}"></script>
<script src="{{ asset('assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
<script src="{{ asset('assets/js/sfms.js') }}"></script>
