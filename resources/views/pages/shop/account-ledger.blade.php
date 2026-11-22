<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BusinessDay;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Transfer;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $accountId;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(Account $account): void
    {
        $this->accountId = $account->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('type', 'from', 'to');
        $this->resetPage();
    }

    #[On('money-moved')]
    public function refresh(): void
    {
        unset($this->account, $this->totals, $this->transactions);
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function account(): Account
    {
        return Account::query()->findOrFail($this->accountId);
    }

    /** [start, end] in UTC for the chosen dates (shop's time zone), either side may be open. */
    #[Computed]
    public function range(): array
    {
        $tz = $this->shop->timezone;
        $start = $this->validDate($this->from) ? Carbon::parse($this->from, $tz)->startOfDay()->utc() : null;
        $end = $this->validDate($this->to) ? Carbon::parse($this->to, $tz)->endOfDay()->utc() : null;

        return [$start, $end];
    }

    private function filtered()
    {
        [$start, $end] = $this->range;

        return AccountTransaction::query()
            ->where('account_id', $this->accountId)
            ->when(array_key_exists($this->type, AccountTransaction::TYPES), fn ($q) => $q->where('type', $this->type))
            ->when($start, fn ($q) => $q->where('transacted_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('transacted_at', '<=', $end));
    }

    #[Computed]
    public function transactions()
    {
        return $this->filtered()->with(['user', 'source'])->latest('transacted_at')->latest('id')->paginate(25);
    }

    /** Money in / out for the filter, and the balance before and after the dates. */
    #[Computed]
    public function totals(): array
    {
        [$start, $end] = $this->range;

        $row = $this->filtered()->selectRaw('
            COUNT(*) as entries,
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as money_in,
            COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) as money_out
        ')->first();

        $balanceAt = fn (?Carbon $before) => Money::of(AccountTransaction::query()
            ->where('account_id', $this->accountId)
            ->when($before, fn ($q) => $q->where('transacted_at', '<', $before))
            ->latest('transacted_at')->latest('id')
            ->value('balance_after') ?? 0);

        return [
            'entries' => (int) $row->entries,
            'in' => Money::of($row->money_in),
            'out' => Money::of($row->money_out),
            'opening' => $start ? $balanceAt($start) : null,
            'closing' => $end ? $balanceAt($end->copy()->addSecond()) : Money::of($this->account->current_balance),
        ];
    }

    /** Where an entry came from, with a link when the user may open it. */
    public function sourceLink(AccountTransaction $txn): ?array
    {
        $source = $txn->source;
        $user = auth('shop')->user();

        return match (true) {
            $source instanceof Payment => $user->can('orders.view')
                ? ['label' => 'Order', 'url' => route('shop.orders.show', ['order' => $source->order_id])] : null,
            $source instanceof Expense => $user->can('expenses.manage')
                ? ['label' => 'Expense', 'url' => route('shop.expenses.index', ['search' => $source->voucher_number])] : null,
            $source instanceof Purchase => $user->can('purchases.manage') && $this->shop->inventory_tracking_enabled
                ? ['label' => 'Purchase', 'url' => route('shop.purchases.index', ['search' => $source->voucher_number])] : null,
            $source instanceof Transfer => ($other = (int) $source->from_account_id === $this->accountId ? $source->to_account_id : $source->from_account_id)
                ? ['label' => 'Other account', 'url' => route('shop.accounts.show', ['account' => $other])] : null,
            $source instanceof BusinessDay => $user->can('day.open_close') && ! $source->isOpen()
                ? ['label' => 'Day report', 'url' => route('shop.day.report', ['businessDay' => $source->id])] : null,
            default => null,
        };
    }

    public function tone(AccountTransaction $txn): string
    {
        return match ($txn->type) {
            'sale', 'cash_in', 'transfer_in', 'opening' => 'success',
            'expense', 'purchase', 'cash_out', 'refund' => 'danger',
            'transfer_out' => 'primary',
            default => 'warning',
        };
    }

    private function validDate(string $value): bool
    {
        return $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value);
    }

    public function render()
    {
        return $this->view()->title($this->account->name.' ledger');
    }
};
?>

@php
    $shop = $this->shop;
    $account = $this->account;
    $tz = $shop->timezone;
    $filtered = $this->type !== '' || $this->from !== '' || $this->to !== '';
@endphp

<div>
    <x-page-header :title="$account->name" :subtitle="$account->typeLabel().($account->account_number ? ' · '.$account->account_number : '').($account->is_active ? '' : ' · inactive')">
        <x-slot:actions>
            <a href="{{ route('shop.accounts.index') }}" wire:navigate class="btn btn-light btn-touch"><i class="ri-arrow-left-line"></i>Accounts</a>
            @if ($account->is_active)
                <div class="btn-group">
                    <button type="button" class="btn btn-light btn-touch" wire:click="$dispatch('move-money', { mode: 'in', account: {{ $account->id }} })">
                        <i class="ri-add-line"></i>Money in
                    </button>
                    <button type="button" class="btn btn-light btn-touch" wire:click="$dispatch('move-money', { mode: 'out', account: {{ $account->id }} })">
                        <i class="ri-subtract-line"></i>Money out
                    </button>
                </div>
                <button type="button" class="btn btn-primary btn-touch" wire:click="$dispatch('move-money', { mode: 'transfer', account: {{ $account->id }} })">
                    <i class="ri-arrow-left-right-line"></i>Transfer
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        @if ($this->totals['opening'] !== null)
            <div class="col-6 col-xl-3"><x-kpi label="Balance before" :value="$shop->money($this->totals['opening'])" icon="ri-history-line" tone="secondary" :hint="\Illuminate\Support\Carbon::parse($this->from)->format('d M Y')" /></div>
        @else
            <div class="col-6 col-xl-3"><x-kpi label="Entries" :value="number_format($this->totals['entries'])" :icon="$account->icon()" :tone="$account->tone()" :hint="$filtered ? 'For this filter' : 'All time'" /></div>
        @endif
        <div class="col-6 col-xl-3"><x-kpi label="Money in" :value="$shop->money($this->totals['in'])" icon="ri-arrow-down-circle-line" tone="success" :hint="$filtered ? 'For this filter' : 'All time'" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Money out" :value="$shop->money(abs((float) $this->totals['out']))" icon="ri-arrow-up-circle-line" tone="danger" :hint="$filtered ? 'For this filter' : 'All time'" /></div>
        <div class="col-6 col-xl-3"><x-kpi :label="$this->to !== '' ? 'Balance at end' : 'Balance now'" :value="$shop->money($this->totals['closing'])" icon="ri-scales-3-line" tone="primary"
                                           :hint="$this->to !== '' ? \Illuminate\Support\Carbon::parse($this->to)->format('d M Y') : null" /></div>
    </div>

    <div class="card mb-0">
        <div class="card-header">
            <div class="row g-2">
                <div class="col-md-4">
                    <select class="form-select form-select-touch" wire:model.live="type" aria-label="Type">
                        <option value="">All entries</option>
                        @foreach (\App\Models\AccountTransaction::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <input type="date" class="form-control form-control-touch" wire:model.live="from" aria-label="From date" title="From">
                </div>
                <div class="col-6 col-md-3">
                    <input type="date" class="form-control form-control-touch" wire:model.live="to" aria-label="To date" title="To">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-light btn-touch w-100" wire:click="clearFilters" @disabled(! $filtered)>Clear</button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            @if ($this->transactions->isEmpty())
                <x-empty-state icon="ri-exchange-dollar-line" title="No entries">
                    {{ $filtered ? 'Nothing matches these filters.' : 'Sales, expenses and transfers through this account will appear here.' }}
                </x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>When</th><th>Entry</th><th>Details</th><th>By</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->transactions as $txn)
                                @php
                                    $link = $this->sourceLink($txn);
                                    $tone = $this->tone($txn);
                                    $negative = (float) $txn->amount < 0;
                                @endphp
                                <tr wire:key="txn-{{ $txn->id }}">
                                    <td data-label="When" class="text-muted fs-13 text-nowrap">{{ $txn->transacted_at->timezone($tz)->format('d M Y, h:i A') }}</td>
                                    <td class="td-primary"><span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}">{{ $txn->typeLabel() }}</span></td>
                                    <td data-label="Details" class="fs-13">
                                        {{ $txn->note ?: '—' }}
                                        @if ($link)
                                            <a href="{{ $link['url'] }}" wire:navigate class="ms-1 text-nowrap">{{ $link['label'] }} <i class="ri-arrow-right-up-line"></i></a>
                                        @endif
                                    </td>
                                    <td data-label="By" class="fs-13">{{ $txn->user?->name ?? '—' }}</td>
                                    <td data-label="Amount" class="text-end fw-semibold text-nowrap {{ $negative ? 'text-danger' : 'text-success' }}">
                                        {{ $negative ? '−' : '+' }}{{ $shop->money(abs((float) $txn->amount)) }}
                                    </td>
                                    <td data-label="Balance" class="text-end text-nowrap {{ (float) $txn->balance_after < 0 ? 'text-danger' : '' }}">{{ $shop->money($txn->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->transactions->hasPages())
                    <div class="p-3 border-top">{{ $this->transactions->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    <livewire:accounts.move-money />
</div>
