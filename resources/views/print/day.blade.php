@extends('print.layout', ['title' => 'Day report '.$day->business_date->format('d M Y')])

@php
    $tz = $shop->timezone;
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 2);
@endphp

@section('content')
    <div class="center">
        <h1 class="big">{{ $shop->name }}</h1>
        <p class="bold upper" style="margin-top:1mm">{{ $day->isOpen() ? 'Day report (X, day still open)' : 'Day close report (Z)' }}</p>
        <p>{{ $day->label() }}</p>
    </div>

    <hr class="rule">
    <div class="row small"><span>Opened</span><span>{{ $day->opened_at->timezone($tz)->format('d M h:i A') }} · {{ $day->openedBy?->name }}</span></div>
    @if ($day->closed_at)
        <div class="row small"><span>Closed</span><span>{{ $day->closed_at->timezone($tz)->format('d M h:i A') }} · {{ $day->closedBy?->name }}</span></div>
    @endif

    <hr class="rule-solid">
    <div class="row big"><span>SALES</span><span>{{ $money($summary['sales']) }}</span></div>
    <div class="row"><span>Orders</span><span>{{ $summary['orders'] }}</span></div>
    <div class="row"><span>Average order</span><span>{{ $money($summary['average']) }}</span></div>
    <div class="row"><span>Cancelled orders</span><span>{{ $summary['cancelled'] }}</span></div>
    <hr class="rule-solid">

    <p class="bold">Payments</p>
    @forelse ($summary['by_account'] as $row)
        <div class="row"><span>{{ $row['account'] }} ({{ $row['payments'] }})</span><span>{{ $money($row['amount']) }}</span></div>
    @empty
        <p class="small">No payments.</p>
    @endforelse

    <hr class="rule">
    <p class="bold">Order types</p>
    @foreach ($summary['by_type'] as $row)
        <div class="row"><span>{{ $row['type'] }} ({{ $row['orders'] }})</span><span>{{ $money($row['amount']) }}</span></div>
    @endforeach

    <hr class="rule">
    <div class="row"><span>Gross items</span><span>{{ $money($summary['subtotal']) }}</span></div>
    <div class="row"><span>Discounts</span><span>-{{ $money($summary['discounts']) }}</span></div>
    <div class="row"><span>Service charge</span><span>{{ $money($summary['service_charge']) }}</span></div>
    <div class="row"><span>Delivery charges</span><span>{{ $money($summary['delivery_charge']) }}</span></div>
    <div class="row"><span>Voids ({{ $summary['voids']['quantity'] }} items)</span><span>{{ $money($summary['voids']['amount']) }}</span></div>
    @if ($summary['open_tabs']['count'] > 0)
        <div class="row"><span>Open tabs ({{ $summary['open_tabs']['count'] }})</span><span>{{ $money($summary['open_tabs']['amount']) }}</span></div>
    @endif

    <hr class="rule-solid">
    <p class="bold">Cash drawer</p>
    <div class="row"><span>Opening count</span><span>{{ $money($summary['cash']['opening']) }}</span></div>
    <div class="row"><span>+ Cash sales</span><span>{{ $money($summary['cash']['sales']) }}</span></div>
    @if ((float) $summary['cash']['other'] != 0)
        <div class="row"><span>+/- Other</span><span>{{ $money($summary['cash']['other']) }}</span></div>
    @endif
    <div class="row bold"><span>Expected</span><span>{{ $money($summary['cash']['expected']) }}</span></div>
    @if ($summary['cash']['counted'] !== null)
        <div class="row bold"><span>Counted</span><span>{{ $money($summary['cash']['counted']) }}</span></div>
        <div class="row big"><span>{{ (float) $summary['cash']['difference'] < 0 ? 'SHORT' : ((float) $summary['cash']['difference'] > 0 ? 'OVER' : 'DIFFERENCE') }}</span><span>{{ $money(abs((float) $summary['cash']['difference'])) }}</span></div>
    @endif

    @if ($summary['items'] !== [])
        <hr class="rule-solid">
        <p class="bold">Items sold</p>
        @foreach ($summary['items'] as $row)
            <div class="row small"><span>{{ $row['quantity'] }} x {{ $row['name'] }}</span><span>{{ $money($row['amount']) }}</span></div>
        @endforeach
    @endif

    <hr class="rule">
    <p class="center small">Printed {{ now($tz)->format('d M Y, h:i A') }}</p>
@endsection
