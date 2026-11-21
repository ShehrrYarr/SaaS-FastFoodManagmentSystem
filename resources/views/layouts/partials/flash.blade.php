{{-- One-shot toast after a redirect: session()->flash('toast', ['type' => 'success', 'message' => '...']) --}}
@if (session()->has('toast'))
    <div hidden data-flash-toast="{{ json_encode(session('toast')) }}"></div>
@endif
