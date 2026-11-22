{{--
    Figures for one business day (live while open, stored snapshot once closed).
    @include('partials.day-summary', ['summary' => $summary, 'shop' => $shop])
--}}
@php
    $money = fn ($v) => $shop->money($v);
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><x-kpi label="Sales" :value="$money($summary['sales'])" icon="ri-money-dollar-circle-line" tone="success" /></div>
    <div class="col-6 col-xl-3"><x-kpi label="Orders paid" :value="$summary['orders']" icon="ri-file-list-3-line" tone="primary" :hint="$summary['cancelled'] ? $summary['cancelled'].' cancelled' : null" /></div>
    <div class="col-6 col-xl-3"><x-kpi label="Average order" :value="$money($summary['average'])" icon="ri-line-chart-line" tone="info" /></div>
    <div class="col-6 col-xl-3"><x-kpi label="Open tabs" :value="$summary['open_tabs']['count']" icon="ri-restaurant-2-line" tone="warning" :hint="$summary['open_tabs']['count'] ? $money($summary['open_tabs']['amount']).' unpaid' : null" /></div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card h-100 mb-0">
            <div class="card-header"><h5 class="card-title mb-0">Payments by account</h5></div>
            <div class="card-body">
                @forelse ($summary['by_account'] as $row)
                    <div class="hstack justify-content-between py-1">
                        <span>{{ $row['account'] }} <span class="text-muted fs-12">&times;{{ $row['payments'] }}</span></span>
                        <span class="fw-semibold">{{ $money($row['amount']) }}</span>
                    </div>
                @empty
                    <p class="text-muted mb-0">No payments yet.</p>
                @endforelse
                <hr>
                @foreach ($summary['by_type'] as $row)
                    <div class="hstack justify-content-between py-1 fs-13">
                        <span class="text-muted">{{ $row['type'] }} <span class="fs-12">&times;{{ $row['orders'] }}</span></span>
                        <span>{{ $money($row['amount']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100 mb-0">
            <div class="card-header"><h5 class="card-title mb-0">Cash drawer</h5></div>
            <div class="card-body">
                <div class="hstack justify-content-between py-1"><span class="text-muted">Opening count</span><span>{{ $money($summary['cash']['opening']) }}</span></div>
                <div class="hstack justify-content-between py-1"><span class="text-muted">+ Cash sales</span><span>{{ $money($summary['cash']['sales']) }}</span></div>
                @foreach ($summary['cash']['movements'] ?? [] as $row)
                    <div class="hstack justify-content-between py-1">
                        <span class="text-muted">{{ (float) $row['amount'] < 0 ? '−' : '+' }} {{ $row['label'] }} <span class="fs-12">&times;{{ $row['entries'] }}</span></span>
                        <span>{{ $money(abs((float) $row['amount'])) }}</span>
                    </div>
                @endforeach
                @if ((float) $summary['cash']['other'] != 0)
                    {{-- Days closed before M5 kept every non-sale movement here; now it is only what the ledger cannot explain. --}}
                    <div class="hstack justify-content-between py-1 {{ isset($summary['cash']['movements']) ? 'text-danger' : '' }}">
                        <span class="{{ isset($summary['cash']['movements']) ? '' : 'text-muted' }}">{{ isset($summary['cash']['movements']) ? 'Not in the ledger' : '± Other cash movements' }}</span>
                        <span>{{ $money($summary['cash']['other']) }}</span>
                    </div>
                @endif
                <div class="hstack justify-content-between py-1 border-top mt-1 pt-2 fw-semibold"><span>Expected in drawer</span><span>{{ $money($summary['cash']['expected']) }}</span></div>
                @if ($summary['cash']['counted'] !== null)
                    @php($diff = (float) $summary['cash']['difference'])
                    <div class="hstack justify-content-between py-1 fw-semibold"><span>Counted</span><span>{{ $money($summary['cash']['counted']) }}</span></div>
                    <div class="hstack justify-content-between py-1 fw-bold {{ $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-warning' : 'text-success') }}">
                        <span>{{ $diff < 0 ? 'Short' : ($diff > 0 ? 'Over' : 'Matches') }}</span>
                        <span>{{ $diff == 0 ? '✓' : $money(abs($diff)) }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100 mb-0">
            <div class="card-header"><h5 class="card-title mb-0">Adjustments</h5></div>
            <div class="card-body">
                <div class="hstack justify-content-between py-1"><span class="text-muted">Gross items</span><span>{{ $money($summary['subtotal']) }}</span></div>
                <div class="hstack justify-content-between py-1"><span class="text-muted">Discounts</span><span class="text-danger">−{{ $money($summary['discounts']) }}</span></div>
                <div class="hstack justify-content-between py-1"><span class="text-muted">Service charge</span><span>{{ $money($summary['service_charge']) }}</span></div>
                <div class="hstack justify-content-between py-1"><span class="text-muted">Delivery charges</span><span>{{ $money($summary['delivery_charge']) }}</span></div>
                <div class="hstack justify-content-between py-1"><span class="text-muted">Voided items</span><span>{{ $summary['voids']['quantity'] }} &middot; {{ $money($summary['voids']['amount']) }}</span></div>
            </div>
        </div>
    </div>

    @if (($summary['expenses']['count'] ?? 0) > 0 || ($summary['purchases']['count'] ?? 0) > 0)
        <div class="col-lg-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Expenses &amp; purchases</h5></div>
                <div class="card-body">
                    @foreach ($summary['expenses']['by_category'] as $row)
                        <div class="hstack justify-content-between py-1"><span class="text-muted">{{ $row['category'] }} <span class="fs-12">&times;{{ $row['count'] }}</span></span><span>{{ $money($row['amount']) }}</span></div>
                    @endforeach
                    @if ($summary['expenses']['count'] > 0)
                        <div class="hstack justify-content-between py-1 border-top mt-1 pt-2 fw-semibold"><span>Expenses</span><span>{{ $money($summary['expenses']['amount']) }}</span></div>
                    @endif
                    @if ($summary['purchases']['count'] > 0)
                        <div class="hstack justify-content-between py-1"><span class="text-muted">Stock purchases <span class="fs-12">&times;{{ $summary['purchases']['count'] }}</span></span><span>{{ $money($summary['purchases']['amount']) }}</span></div>
                    @endif
                    <p class="fs-12 text-muted mt-2 mb-0">Recorded while this day was open, from any account.</p>
                </div>
            </div>
        </div>
    @endif

    @if ($summary['items'] !== [])
        <div class="{{ ($summary['expenses']['count'] ?? 0) > 0 || ($summary['purchases']['count'] ?? 0) > 0 ? 'col-lg-8' : 'col-12' }}">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Items sold</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 table-stack">
                            <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
                            <tbody>
                                @foreach ($summary['items'] as $row)
                                    <tr>
                                        <td class="td-primary fw-medium">{{ $row['name'] }}</td>
                                        <td data-label="Qty" class="text-end">{{ $row['quantity'] }}</td>
                                        <td data-label="Amount" class="text-end">{{ $money($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
