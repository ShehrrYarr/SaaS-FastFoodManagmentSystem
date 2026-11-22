<?php

use App\Exceptions\PosException;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Services\BusinessDays;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Business Day')] class extends Component
{
    use WithPagination;

    public string $openingCount = '';
    public string $openingNote = '';
    public bool $showClose = false;
    public string $closingCount = '';
    public string $closingNote = '';

    public function mount(): void
    {
        $this->openingCount = $this->plain($this->drawer->current_balance);
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function day(): ?BusinessDay
    {
        return app(BusinessDays::class)->current()?->load('openedBy');
    }

    #[Computed]
    public function drawer()
    {
        return app(BusinessDays::class)->cashDrawer();
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->day ? app(BusinessDays::class)->summary($this->day) : null;
    }

    #[Computed]
    public function history()
    {
        return BusinessDay::query()->where('status', 'closed')->with(['openedBy', 'closedBy'])
            ->latest('opened_at')->latest('id')->paginate(10);
    }

    public function openDay(BusinessDays $days): void
    {
        $this->validate(
            ['openingCount' => ['required', 'numeric', 'min:0', 'max:99999999'], 'openingNote' => ['nullable', 'string', 'max:255']],
            [],
            ['openingCount' => 'cash counted'],
        );

        try {
            $days->open($this->openingCount, $this->openingNote);
        } catch (PosException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->reset('openingNote');
        unset($this->day, $this->summary, $this->drawer);
        $this->dispatch('toast', type: 'success', message: 'The day is open. Happy selling!');
    }

    public function startClose(): void
    {
        $this->closingCount = '';
        $this->closingNote = '';
        $this->resetValidation();
        unset($this->drawer, $this->summary);
        $this->showClose = true;
    }

    public function closeDay(BusinessDays $days): void
    {
        $this->validate(
            ['closingCount' => ['required', 'numeric', 'min:0', 'max:99999999'], 'closingNote' => ['nullable', 'string', 'max:255']],
            [],
            ['closingCount' => 'cash counted'],
        );

        if (! $this->day) {
            $this->showClose = false;

            return;
        }

        try {
            $closed = $days->close($this->day, $this->closingCount, $this->closingNote);
        } catch (PosException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->showClose = false;
        $this->openingCount = $this->plain($closed->counted_cash);
        unset($this->day, $this->summary, $this->history, $this->drawer);

        $diff = (float) $closed->cash_difference;
        $this->dispatch('toast', type: $diff == 0 ? 'success' : 'warning', message: 'Day closed. '.match (true) {
            $diff < 0 => 'Cash short by '.$this->shop->money(abs($diff)).'.',
            $diff > 0 => 'Cash over by '.$this->shop->money($diff).'.',
            default => 'Cash matches.',
        });
        $this->dispatch('sfms-print', url: route('shop.print.day', ['businessDay' => $closed->id]), kind: 'receipt');
    }

    private function plain(string|float|null $amount): string
    {
        $amount = Money::of($amount);

        return str_ends_with($amount, '.00') ? substr($amount, 0, -3) : $amount;
    }
};
?>

<div>
    @if ($this->day)
        <x-page-header title="Business day" :subtitle="$this->day->label().' · opened '.$this->day->opened_at->timezone($this->shop->timezone)->format('h:i A').' by '.($this->day->openedBy?->name ?? '—')">
            <x-slot:actions>
                <button type="button" class="btn btn-light btn-touch" onclick="sfmsPrint.url('{{ route('shop.print.day', ['businessDay' => $this->day->id]) }}')">
                    <i class="ri-printer-line"></i>Print X report
                </button>
                <button type="button" class="btn btn-danger btn-touch" wire:click="startClose">
                    <i class="ri-lock-line"></i>Close day
                </button>
            </x-slot:actions>
        </x-page-header>

        @include('partials.day-summary', ['summary' => $this->summary, 'shop' => $this->shop])
    @else
        <x-page-header title="Business day" subtitle="Open the day to start taking orders. Close it at night with a cash count." />

        <div class="row justify-content-center mb-4">
            <div class="col-md-8 col-lg-6 col-xl-5">
                <div class="card mb-0">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <span class="avatar avatar-lg avatar-title rounded-circle bg-success-subtle text-success border-0 mx-auto mb-3"><i class="ri-sun-line fs-24"></i></span>
                            <h5 class="mb-1">Open {{ $this->shop->today()->format('l, d M') }}</h5>
                            <p class="text-muted mb-0">Count the cash in the drawer before you start.</p>
                        </div>

                        <form wire:submit="openDay" novalidate x-data="{ expected: {{ (float) $this->drawer->current_balance }} }">
                            <div class="hstack justify-content-between bg-light rounded-3 px-3 py-2 mb-3">
                                <span class="text-muted">Cash Drawer should have</span>
                                <span class="fw-semibold">{{ $this->shop->money($this->drawer->current_balance) }}</span>
                            </div>

                            <x-input model="openingCount" label="Cash counted in the drawer" inputmode="decimal" :prepend="$this->shop->currency_symbol" required class="mb-2" />

                            <div class="fs-13 mb-3" x-cloak x-show="$wire.openingCount !== '' && Math.abs(parseFloat($wire.openingCount || 0) - expected) >= 0.01">
                                <span class="text-warning fw-medium">
                                    <i class="ri-error-warning-line"></i>
                                    <span x-text="(parseFloat($wire.openingCount || 0) - expected) < 0 ? 'Short by ' : 'Over by '"></span>
                                    {{ $this->shop->currency_symbol }} <span x-text="Math.abs(parseFloat($wire.openingCount || 0) - expected).toLocaleString()"></span>.
                                </span>
                                <span class="text-muted">The difference is recorded as cash over/short.</span>
                            </div>

                            <x-input model="openingNote" label="Note (optional)" placeholder="e.g. Float topped up" class="mb-4" />

                            <button type="submit" class="btn btn-success btn-touch w-100" wire:loading.attr="disabled" wire:target="openDay">
                                <i class="ri-play-circle-line"></i>Open the day
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card mb-0 mt-4">
        <div class="card-header"><h5 class="card-title mb-0">Past days</h5></div>
        <div class="card-body p-0">
            @if ($this->history->isEmpty())
                <x-empty-state icon="ri-calendar-check-line" title="No closed days yet">Each day you close appears here with its report.</x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Day</th><th>Closed by</th><th class="text-end">Sales</th><th class="text-end">Orders</th><th class="text-end">Cash</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->history as $past)
                                @php($diff = (float) $past->cash_difference)
                                <tr wire:key="day-{{ $past->id }}">
                                    <td class="td-primary">
                                        <div class="fw-semibold">{{ $past->label() }}</div>
                                        <div class="fs-12 text-muted">{{ $past->opened_at->timezone($this->shop->timezone)->format('h:i A') }} – {{ $past->closed_at?->timezone($this->shop->timezone)->format('d M h:i A') }}</div>
                                    </td>
                                    <td data-label="Closed by">{{ $past->closedBy?->name ?? '—' }}</td>
                                    <td data-label="Sales" class="text-end fw-semibold">{{ $this->shop->money($past->summary['sales'] ?? 0) }}</td>
                                    <td data-label="Orders" class="text-end">{{ $past->summary['orders'] ?? 0 }}</td>
                                    <td data-label="Cash" class="text-end">
                                        @if ($diff == 0)
                                            <span class="badge bg-success-subtle text-success">Matched</span>
                                        @else
                                            <span class="badge {{ $diff < 0 ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning' }}">{{ $diff < 0 ? 'Short' : 'Over' }} {{ $this->shop->money(abs($diff)) }}</span>
                                        @endif
                                    </td>
                                    <td class="td-actions text-end">
                                        <a href="{{ route('shop.day.report', ['businessDay' => $past->id]) }}" wire:navigate class="btn btn-sm btn-light">Report</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->history->hasPages())
                    <div class="p-3 border-top">{{ $this->history->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    @if ($this->day)
        <x-modal wire:model="showClose" title="Close the day">
            <form wire:submit="closeDay" novalidate x-data="{ expected: {{ (float) ($this->summary['cash']['expected'] ?? 0) }} }">
                <div class="modal-body vstack gap-3">
                    @if ($this->summary['open_tabs']['count'] > 0)
                        <div class="alert alert-warning mb-0 fs-13">
                            <i class="ri-error-warning-line me-1"></i>
                            {{ $this->summary['open_tabs']['count'] }} {{ \Illuminate\Support\Str::plural('tab', $this->summary['open_tabs']['count']) }}
                            ({{ $this->shop->money($this->summary['open_tabs']['amount']) }}) still open. They carry over and count on the day they're paid.
                        </div>
                    @endif

                    <div class="text-center bg-light rounded-3 p-3">
                        <div class="text-muted fs-13">Cash expected in the drawer</div>
                        <div class="fs-3 fw-bold">{{ $this->shop->money($this->summary['cash']['expected'] ?? 0) }}</div>
                        <div class="fs-12 text-muted">
                            Opening {{ $this->shop->money($this->summary['cash']['opening']) }} + cash sales {{ $this->shop->money($this->summary['cash']['sales']) }}
                            @if ($this->summary['cash']['movements'] !== [])
                                ± {{ collect($this->summary['cash']['movements'])->pluck('label')->map(fn ($l) => mb_strtolower($l))->implode(', ') }}
                            @endif
                        </div>
                    </div>

                    <x-input model="closingCount" label="Cash counted" inputmode="decimal" :prepend="$this->shop->currency_symbol" required autofocus />

                    <div class="fs-14 fw-semibold" x-cloak x-show="$wire.closingCount !== ''">
                        <template x-if="Math.abs(parseFloat($wire.closingCount || 0) - expected) < 0.01">
                            <span class="text-success"><i class="ri-checkbox-circle-line"></i> Cash matches.</span>
                        </template>
                        <template x-if="Math.abs(parseFloat($wire.closingCount || 0) - expected) >= 0.01">
                            <span :class="(parseFloat($wire.closingCount || 0) - expected) < 0 ? 'text-danger' : 'text-warning'">
                                <i class="ri-error-warning-line"></i>
                                <span x-text="(parseFloat($wire.closingCount || 0) - expected) < 0 ? 'Short by' : 'Over by'"></span>
                                {{ $this->shop->currency_symbol }} <span x-text="Math.abs(parseFloat($wire.closingCount || 0) - expected).toLocaleString()"></span>
                            </span>
                        </template>
                    </div>

                    <x-input model="closingNote" label="Note (optional)" placeholder="e.g. Rs 500 taken for change" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-touch" wire:loading.attr="disabled" wire:target="closeDay">
                        <i class="ri-lock-line"></i>Close day &amp; print
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
