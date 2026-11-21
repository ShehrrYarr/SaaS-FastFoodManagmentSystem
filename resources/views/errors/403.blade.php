@php
    $shopUser = auth('shop')->user();
    $ownShop = $shopUser?->shop;
@endphp

@extends('errors.layout', [
    'title' => 'Access denied',
    'icon' => 'ri-lock-2-line',
    'message' => $exception->getMessage() ?: 'You do not have permission to open this page.',
])

@section('actions')
    @if ($ownShop)
        <p class="text-muted fs-13 mb-3">You are signed in to <strong>{{ $ownShop->name }}</strong>.</p>
        <a href="{{ route('shop.dashboard', ['shop' => $ownShop->prefix]) }}" class="btn btn-primary btn-touch w-100 mb-2">
            Go to {{ $ownShop->name }}
        </a>
    @endif
    <a href="{{ route('home') }}" class="btn btn-light btn-touch w-100">Back to start</a>
@endsection
