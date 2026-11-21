@extends('print.layout', ['title' => $kot->label().' · '.$order->order_number])

@php
    $tz = $shop->timezone;
@endphp

@section('content')
    <p class="center small upper">{{ $shop->name }}</p>

    @if ($kot->isVoid())
        <p class="center big" style="margin-top:2mm">*** VOID ***</p>
    @endif
    <div class="row" style="margin-top:2mm">
        <span class="big">{{ $kot->label() }}</span>
        <span class="big">Token {{ $order->token_number }}</span>
    </div>

    <div class="box center bold upper">
        {{ $order->typeLabel() }}
        @if ($order->table) &middot; {{ $order->table->name }} @endif
        @if ($order->guests) &middot; {{ $order->guests }} pax @endif
    </div>

    <div class="row small"><span>{{ $order->order_number }}</span><span>{{ $kot->created_at->timezone($tz)->format('d M, h:i A') }}</span></div>
    @if ($order->waiter)
        <div class="small">Waiter: {{ $order->waiter->name }}</div>
    @endif
    @if ($order->customer_name && ! $order->isDineIn())
        <div class="small">Customer: {{ $order->customer_name }}</div>
    @endif

    <hr class="rule-solid">

    @if ($kot->isVoid())
        @foreach ($kot->voids as $void)
            <div class="row big">
                <span>{{ $void->quantity }} x {{ $void->item->displayName() }}</span>
            </div>
            @foreach ($void->item->modifiers as $modifier)
                <div class="indent">+ @if ($modifier->group_name){{ $modifier->group_name }}: @endif{{ $modifier->name }}</div>
            @endforeach
            <div class="indent small">Reason: {{ $void->reason }}</div>
            <hr class="rule">
        @endforeach
    @else
        @foreach ($kot->items as $item)
            <div class="big">{{ $item->quantity }} x {{ $item->displayName() }}</div>
            @foreach ($item->modifiers as $modifier)
                <div class="indent bold">+ @if ($modifier->group_name)<span class="small">{{ $modifier->group_name }}:</span> @endif{{ $modifier->name }}</div>
            @endforeach
            @foreach ($item->combo_snapshot ?? [] as $component)
                <div class="indent">- {{ $component['quantity'] * $item->quantity }} x {{ $component['name'] }}</div>
            @endforeach
            @if ($item->note)
                <div class="indent bold">** {{ $item->note }} **</div>
            @endif
            @if (! $loop->last)<hr class="rule">@endif
        @endforeach
    @endif

    @if ($order->notes && ! $kot->isVoid())
        <hr class="rule">
        <div class="bold">Note: {{ $order->notes }}</div>
    @endif

    <hr class="rule-solid">
    <div class="row small">
        <span>By {{ $kot->createdBy?->name ?? '—' }}</span>
        <span>{{ $kot->printed_count > 1 ? 'Reprint '.($kot->printed_count - 1) : '' }}</span>
    </div>
@endsection
