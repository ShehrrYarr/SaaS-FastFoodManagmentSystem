<?php

use App\Services\Reports;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Reports')] class extends Component
{
    public const RANGES = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'week' => 'This week',
        '7d' => 'Last 7 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        '30d' => 'Last 30 days',
        'custom' => 'Custom',
    ];

    public const MAX_DAYS = 366;

    #[Url(except: 'month')]
    public string $range = 'month';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function setRange(string $range): void
    {
        $shown = $this->dates;
        $this->range = array_key_exists($range, self::RANGES) ? $range : 'month';

        if ($this->range === 'custom') {
            // Start the custom range from what was on screen.
            $this->from = $shown['from'];
            $this->to = $shown['to'];
        } else {
            $this->reset('from', 'to');
        }

        unset($this->dates, $this->report);
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    /** @return array{from: string, to: string, label: string} */
    #[Computed]
    public function dates(): array
    {
        $today = $this->shop->today();

        [$from, $to] = match ($this->range) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today],
            '7d' => [$today->copy()->subDays(6), $today],
            '30d' => [$today->copy()->subDays(29), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'custom' => $this->customDates($today),
            default => [$today->copy()->startOfMonth(), $today],
        };

        $label = $from->isSameDay($to)
            ? $from->format('l, d M Y')
            : ($from->isSameYear($to) ? $from->format('d M') : $from->format('d M Y')).' – '.$to->format('d M Y');

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label];
    }

    private function customDates(Carbon $today): array
    {
        $parse = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value)
            ? Carbon::parse($value, $this->shop->timezone)->startOfDay() : null;

        $to = $parse($this->to) ?? $today->copy();
        $to = $to->gt($today) ? $today->copy() : $to;
        $from = $parse($this->from) ?? $to->copy()->startOfMonth();
        $from = $from->gt($to) ? $to->copy() : $from;

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    #[Computed]
    public function report(): array
    {
        return app(Reports::class)->build($this->dates['from'], $this->dates['to']);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            unset($this->dates, $this->report);
        }
    }
};
?>

@php
    $shop = $this->shop;
    $r = $this->report;
    $money = fn ($v) => $shop->money($v);
    $points = $r['trend']['points'];
    $hasSales = $r['orders'] > 0 || (float) $r['sales'] != 0;
    $maxCategory = (float) collect($r['by_category'])->max(fn ($row) => (float) $row['amount']);
    $maxExpense = (float) collect($r['expenses']['by_category'])->max(fn ($row) => (float) $row['amount']);
    $trendChart = [
        'name' => 'Sales',
        'labels' => array_column($points, 'short'),
        'tooltipLabels' => array_column($points, 'label'),
        'values' => array_map('floatval', array_column($points, 'sales')),
        'money' => true,
        'symbol' => $shop->currency_symbol,
    ];
    $hoursChart = [
        'name' => 'Orders',
        'labels' => array_column($r['hours'], 'label'),
        'values' => array_column($r['hours'], 'orders'),
        'money' => false,
        'unit' => 'orders',
        'height' => 200,
    ];
@endphp

<div>
    <x-page-header title="Reports" :subtitle="$this->dates['label'].' · '.$r['business_days'].' '.\Illuminate\Support\Str::plural('business day', $r['business_days'])">
        <x-slot:actions>
            @if ($hasSales)
                <a href="{{ route('shop.reports.export', ['from' => $this->dates['from'], 'to' => $this->dates['to']]) }}" class="btn btn-light btn-touch" download>
                    <i class="ri-download-2-line"></i>Orders CSV
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Range --}}
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="sfms-range" role="group" aria-label="Date range"
                 x-data x-init="const on = $el.querySelector('[aria-pressed]'); if (on) $el.scrollLeft = on.offsetLeft - $el.firstElementChild.offsetLeft - 16">
                @foreach ($this::RANGES as $value => $label)
                    <button type="button" wire:click="setRange('{{ $value }}')"
                            class="btn btn-sm {{ $range === $value ? 'btn-primary' : 'btn-light' }}" @if ($range === $value) aria-pressed="true" @endif>{{ $label }}</button>
                @endforeach
            </div>
            @if ($range === 'custom')
                <div class="row g-2 mt-2">
                    <div class="col-6 col-md-3">
                        <label class="form-label fs-12 text-muted mb-1" for="r-from">From</label>
                        <input type="date" id="r-from" class="form-control form-control-touch" wire:model.live="from" max="{{ $shop->today()->toDateString() }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fs-12 text-muted mb-1" for="r-to">To</label>
                        <input type="date" id="r-to" class="form-control form-control-touch" wire:model.live="to" max="{{ $shop->today()->toDateString() }}">
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="setRange, from, to">
        {{-- Headline figures --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <x-kpi label="Sales" :value="$money($r['sales'])" icon="ri-money-dollar-circle-line" tone="success"
                       :hint="$r['orders'].' '.\Illuminate\Support\Str::plural('order', $r['orders'])" />
            </div>
            <div class="col-6 col-xl-3">
                <x-kpi label="Average order" :value="$money($r['average'])" icon="ri-line-chart-line" tone="info"
                       :hint="$r['guests'] ? $r['guests'].' dine-in guests' : null" />
            </div>
            <div class="col-6 col-xl-3">
                <x-kpi label="Expenses" :value="$money($r['expenses']['amount'])" icon="ri-wallet-3-line" tone="danger"
                       :hint="$r['expenses']['count'].' '.\Illuminate\Support\Str::plural('expense', $r['expenses']['count'])" />
            </div>
            <div class="col-6 col-xl-3">
                <x-kpi label="Profit (estimate)" :value="$money($r['profit'])" icon="ri-scales-3-line" :tone="(float) $r['profit'] < 0 ? 'danger' : 'primary'"
                       :hint="$r['cost'] !== null ? 'Sales − ingredients − expenses' : 'Sales − expenses'" />
            </div>
        </div>

        @if (! $hasSales && $r['expenses']['count'] === 0)
            <div class="card mb-0">
                <x-empty-state icon="ri-bar-chart-box-line" title="Nothing in this range">
                    {{ $r['business_days'] === 0 ? 'The shop wasn\'t open on these dates.' : 'No orders were paid on these dates.' }} Try another range.
                </x-empty-state>
            </div>
        @else
            {{-- Trend --}}
            <div class="card mb-4">
                <div class="card-header hstack justify-content-between gap-2">
                    <h5 class="card-title mb-0">Sales by {{ $r['trend']['by'] }}</h5>
                    <span class="fs-13 text-muted">Counted on the business day each order was paid</span>
                </div>
                <div class="card-body">
                    <div class="sfms-chart" wire:ignore wire:key="trend-{{ md5(json_encode($trendChart)) }}" x-data="sfmsBarChart(@js($trendChart))"
                         role="img" aria-label="Sales by {{ $r['trend']['by'] }}, {{ $this->dates['label'] }}. The figures are in the table below the chart.">
                        <div x-ref="plot"></div>
                    </div>
                    <details class="mt-2">
                        <summary class="fs-13 text-muted">Show the figures</summary>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr><th>{{ $r['trend']['by'] === 'month' ? 'Month' : 'Day' }}</th><th class="text-end">Orders</th><th class="text-end">Sales</th></tr></thead>
                                <tbody>
                                    @foreach ($points as $point)
                                        <tr>
                                            <td>{{ $point['label'] }}</td>
                                            <td class="text-end">{{ $point['orders'] }}</td>
                                            <td class="text-end">{{ $money($point['sales']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>

            <div class="row g-3 mb-4">
                {{-- Money --}}
                <div class="col-lg-4">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Profit (estimate)</h5></div>
                        <div class="card-body sfms-figures">
                            <div><span class="text-muted">Sales</span><span>{{ $money($r['sales']) }}</span></div>
                            @if ($r['cost'] !== null)
                                <div>
                                    <span class="text-muted">− Ingredient cost
                                        @if ($r['food_cost_percent'] !== null)<span class="fs-12">({{ $r['food_cost_percent'] }}% of sales)</span>@endif
                                    </span>
                                    <span>{{ $money($r['cost']) }}</span>
                                </div>
                            @endif
                            <div><span class="text-muted">− Expenses</span><span>{{ $money($r['expenses']['amount']) }}</span></div>
                            <div class="is-total {{ (float) $r['profit'] < 0 ? 'text-danger' : '' }}"><span>Profit</span><span>{{ $money($r['profit']) }}</span></div>
                            <p class="fs-12 text-muted mt-2 mb-0">
                                @if ($r['cost'] !== null)
                                    Ingredient cost is what the recipes took from stock, at average cost.
                                    Stock bought in this range: {{ $money($r['purchases']['amount']) }} ({{ $r['purchases']['count'] }} {{ \Illuminate\Support\Str::plural('purchase', $r['purchases']['count']) }}).
                                @else
                                    Turn on inventory tracking to include ingredient costs.
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Payments & types --}}
                <div class="col-lg-4">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Payments</h5></div>
                        <div class="card-body sfms-figures">
                            @forelse ($r['by_account'] as $row)
                                <div><span>{{ $row['account'] }} <span class="text-muted fs-12">&times;{{ $row['payments'] }}</span></span><span class="fw-semibold">{{ $money($row['amount']) }}</span></div>
                            @empty
                                <p class="text-muted mb-0">No payments.</p>
                            @endforelse
                            <hr class="my-2">
                            @foreach ($r['by_type'] as $row)
                                <div class="fs-13"><span class="text-muted">{{ $row['type'] }} <span class="fs-12">&times;{{ $row['orders'] }}</span></span><span>{{ $money($row['amount']) }}</span></div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Adjustments --}}
                <div class="col-lg-4">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Adjustments</h5></div>
                        <div class="card-body sfms-figures">
                            <div><span class="text-muted">Gross items</span><span>{{ $money($r['subtotal']) }}</span></div>
                            <div><span class="text-muted">Discounts</span><span>−{{ $money($r['discounts']) }}</span></div>
                            <div><span class="text-muted">Service charge</span><span>{{ $money($r['service_charge']) }}</span></div>
                            <div><span class="text-muted">Delivery charges</span><span>{{ $money($r['delivery_charge']) }}</span></div>
                            <div><span class="text-muted">Voided items</span><span>{{ $r['voids']['quantity'] }} &middot; {{ $money($r['voids']['amount']) }}</span></div>
                            <div><span class="text-muted">Cancelled orders</span><span>{{ $r['cancelled'] }}</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                {{-- Items --}}
                <div class="col-lg-7">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Best sellers</h5></div>
                        <div class="card-body p-0">
                            @if ($r['items'] === [])
                                <p class="text-muted p-3 mb-0">No items sold.</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 table-stack">
                                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Sales</th></tr></thead>
                                        <tbody>
                                            @foreach ($r['items'] as $row)
                                                <tr>
                                                    <td class="td-primary fw-medium">{{ $row['name'] }}</td>
                                                    <td data-label="Qty" class="text-end">{{ $row['quantity'] }}</td>
                                                    <td data-label="Sales" class="text-end">{{ $money($row['amount']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Categories --}}
                <div class="col-lg-5">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Sales by category</h5></div>
                        <div class="card-body">
                            @forelse ($r['by_category'] as $row)
                                <div class="mb-3">
                                    <div class="hstack justify-content-between fs-13 mb-1">
                                        <span class="fw-medium text-truncate me-2">{{ $row['category'] }} <span class="text-muted fw-normal">&times;{{ $row['quantity'] }}</span></span>
                                        <span class="fw-semibold text-nowrap">{{ $money($row['amount']) }}</span>
                                    </div>
                                    <div class="sfms-meter" role="img" aria-label="{{ $row['category'] }}: {{ $money($row['amount']) }}">
                                        <span style="width: {{ $maxCategory > 0 ? max(2, round((float) $row['amount'] / $maxCategory * 100, 1)) : 0 }}%"></span>
                                    </div>
                                </div>
                            @empty
                                <p class="text-muted mb-0">No items sold.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                {{-- Busy hours --}}
                <div class="col-lg-7">
                    <div class="card h-100 mb-0">
                        <div class="card-header hstack justify-content-between gap-2">
                            <h5 class="card-title mb-0">Busy hours</h5>
                            <span class="fs-13 text-muted">Orders paid per hour</span>
                        </div>
                        <div class="card-body">
                            @if ($r['hours'] === [])
                                <p class="text-muted mb-0">No orders paid.</p>
                            @else
                                <div class="sfms-chart is-short" wire:ignore wire:key="hours-{{ md5(json_encode($hoursChart)) }}" x-data="sfmsBarChart(@js($hoursChart))"
                                     role="img" aria-label="Orders paid per hour. The figures are in the table below the chart.">
                                    <div x-ref="plot"></div>
                                </div>
                                <details class="mt-2">
                                    <summary class="fs-13 text-muted">Show the figures</summary>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light"><tr><th>Hour</th><th class="text-end">Orders</th><th class="text-end">Sales</th></tr></thead>
                                            <tbody>
                                                @foreach ($r['hours'] as $row)
                                                    <tr><td>{{ $row['label'] }}</td><td class="text-end">{{ $row['orders'] }}</td><td class="text-end">{{ $money($row['sales']) }}</td></tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Expenses --}}
                <div class="col-lg-5">
                    <div class="card h-100 mb-0">
                        <div class="card-header hstack justify-content-between">
                            <h5 class="card-title mb-0">Expenses</h5>
                            @can('expenses.manage')
                                <a href="{{ route('shop.expenses.index') }}" wire:navigate class="fs-13">Open expenses</a>
                            @endcan
                        </div>
                        <div class="card-body">
                            @forelse ($r['expenses']['by_category'] as $row)
                                <div class="mb-3">
                                    <div class="hstack justify-content-between fs-13 mb-1">
                                        <span class="fw-medium text-truncate me-2">{{ $row['category'] }} <span class="text-muted fw-normal">&times;{{ $row['count'] }}</span></span>
                                        <span class="fw-semibold text-nowrap">{{ $money($row['amount']) }}</span>
                                    </div>
                                    <div class="sfms-meter" role="img" aria-label="{{ $row['category'] }}: {{ $money($row['amount']) }}">
                                        <span style="width: {{ $maxExpense > 0 ? max(2, round((float) $row['amount'] / $maxExpense * 100, 1)) : 0 }}%"></span>
                                    </div>
                                </div>
                            @empty
                                <p class="text-muted mb-0">No expenses in this range.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- Staff --}}
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Cashiers</h5></div>
                        <div class="card-body p-0">
                            @if ($r['cashiers'] === [])
                                <p class="text-muted p-3 mb-0">No orders paid.</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 table-stack">
                                        <thead class="table-light"><tr><th>Closed by</th><th class="text-end">Orders</th><th class="text-end">Sales</th></tr></thead>
                                        <tbody>
                                            @foreach ($r['cashiers'] as $row)
                                                <tr><td class="td-primary fw-medium">{{ $row['name'] }}</td><td data-label="Orders" class="text-end">{{ $row['orders'] }}</td><td data-label="Sales" class="text-end">{{ $money($row['amount']) }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h5 class="card-title mb-0">Waiters</h5></div>
                        <div class="card-body p-0">
                            @if ($r['waiters'] === [])
                                <p class="text-muted p-3 mb-0">No dine-in orders with a waiter.</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 table-stack">
                                        <thead class="table-light"><tr><th>Waiter</th><th class="text-end">Orders</th><th class="text-end">Guests</th><th class="text-end">Sales</th></tr></thead>
                                        <tbody>
                                            @foreach ($r['waiters'] as $row)
                                                <tr>
                                                    <td class="td-primary fw-medium">{{ $row['name'] }}</td>
                                                    <td data-label="Orders" class="text-end">{{ $row['orders'] }}</td>
                                                    <td data-label="Guests" class="text-end">{{ $row['guests'] }}</td>
                                                    <td data-label="Sales" class="text-end">{{ $money($row['amount']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
