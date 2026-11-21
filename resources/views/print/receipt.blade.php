@extends('print.layout', ['title' => 'Receipt '.$order->order_number])

@php
    $tz = $shop->timezone;
    $money = fn ($v) => number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 2);
    $items = $order->items->filter(fn ($i) => $i->activeQuantity() > 0);
@endphp

@section('content')
    <div class="center">
        <h1 class="big">{{ $shop->name }}</h1>
        @if ($shop->address)<p class="small">{{ $shop->address }}</p>@endif
        @if ($shop->phone)<p class="small">Tel: {{ $shop->phone }}</p>@endif
        @if ($shop->receipt_header)<p class="small" style="margin-top:1mm">{{ $shop->receipt_header }}</p>@endif
    </div>

    <hr class="rule">

    <div class="row"><span class="bold">{{ $order->order_number }}</span><span class="bold">Token {{ $order->token_number }}</span></div>
    <div class="row small"><span>{{ $order->typeLabel() }}@if ($order->table) &middot; {{ $order->table->name }}@endif</span><span>{{ ($order->closed_at ?? $order->opened_at)->timezone($tz)->format('d M Y, h:i A') }}</span></div>
    @if ($order->waiter)<div class="small">Waiter: {{ $order->waiter->name }}</div>@endif
    @if ($order->customer_name || $order->customer_phone)
        <div class="small">Customer: {{ $order->customer_name }} {{ $order->customer_phone }}</div>
    @endif
    @if ($order->customer_address)<div class="small">{{ $order->customer_address }}</div>@endif
    @if ($order->rider)<div class="small">Rider: {{ $order->rider->name }}</div>@endif

    <hr class="rule">

    <table>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item->activeQuantity() }} x {{ $item->displayName() }}</td>
                <td class="right">{{ $money($item->grossTotal()) }}</td>
            </tr>
            @foreach ($item->modifiers as $modifier)
                <tr><td class="indent small" colspan="2">+ {{ $modifier->name }}@if ((float) $modifier->price > 0) ({{ $money($modifier->price) }})@endif</td></tr>
            @endforeach
            @if ((float) $item->discount_amount > 0)
                <tr>
                    <td class="indent small">Discount{{ $item->discount_type === 'percent' ? ' '.rtrim(rtrim($item->discount_value, '0'), '.').'%' : '' }}</td>
                    <td class="right small">-{{ $money($item->discount_amount) }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <hr class="rule">

    <div class="row"><span>Subtotal</span><span>{{ $money($order->subtotal) }}</span></div>
    @if ((float) $order->item_discount_total > 0)
        <div class="row"><span>Item discounts</span><span>-{{ $money($order->item_discount_total) }}</span></div>
    @endif
    @if ((float) $order->discount_amount > 0)
        <div class="row"><span>Discount{{ $order->discount_type === 'percent' ? ' ('.rtrim(rtrim($order->discount_value, '0'), '.').'%)' : '' }}</span><span>-{{ $money($order->discount_amount) }}</span></div>
    @endif
    @if ((float) $order->service_charge > 0)
        <div class="row"><span>Service charge{{ $order->service_charge_type === 'percent' ? ' ('.rtrim(rtrim($order->service_charge_value, '0'), '.').'%)' : '' }}</span><span>{{ $money($order->service_charge) }}</span></div>
    @endif
    @if ((float) $order->delivery_charge > 0 && $order->isDelivery())
        <div class="row"><span>Delivery</span><span>{{ $money($order->delivery_charge) }}</span></div>
    @endif

    <hr class="rule-solid">
    <div class="row big"><span>TOTAL</span><span>{{ $shop->currency_symbol }} {{ $money($order->grand_total) }}</span></div>
    <hr class="rule-solid">

    @if ($order->payments->isNotEmpty())
        @foreach ($order->payments as $payment)
            <div class="row"><span>{{ $payment->account?->name }}@if ($payment->reference) <span class="small">({{ $payment->reference }})</span>@endif</span><span>{{ $money($payment->amount) }}</span></div>
            @if ($payment->tendered !== null)
                <div class="row small indent"><span>Received</span><span>{{ $money($payment->tendered) }}</span></div>
                <div class="row small indent"><span>Change</span><span>{{ $money($payment->change_given) }}</span></div>
            @endif
        @endforeach
    @elseif ($order->isOpen())
        <p class="center bold">*** BILL · NOT PAID ***</p>
    @endif

    @if ($order->status === 'cancelled')
        <p class="center bold" style="margin-top:2mm">*** CANCELLED ***</p>
    @endif

    <hr class="rule">
    <div class="center small">
        @if ($shop->receipt_footer)<p>{{ $shop->receipt_footer }}</p>@endif
        <p>Thank you!</p>
        @if ($order->closedBy)<p class="muted">Cashier: {{ $order->closedBy->name }}</p>@endif
    </div>
@endsection
