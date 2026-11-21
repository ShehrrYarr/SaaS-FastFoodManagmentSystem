@extends('errors.layout', [
    'title' => 'Not found',
    'icon' => 'ri-compass-3-line',
    'tone' => 'bg-secondary',
    'message' => 'We could not find that page or shop. Check the address and try again.',
])

@section('actions')
    <a href="{{ route('home') }}" class="btn btn-primary btn-touch w-100">Find your shop</a>
@endsection
