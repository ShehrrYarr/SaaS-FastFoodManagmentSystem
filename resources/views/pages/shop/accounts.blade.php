<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\AccountLedger;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Accounts')] class extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'bank';
    public string $accountTitle = '';
    public string $accountNumber = '';
    public string $openingBalance = '';

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function accounts()
    {
        return Account::query()->ordered()->withCount('transactions')->get();
    }

    #[Computed]
    public function recent()
    {
        return AccountTransaction::query()->with('account')->latest('transacted_at')->latest('id')->limit(10)->get();
    }

    #[Computed]
    public function totals(): array
    {
        $active = $this->accounts->where('is_active', true);
        $sum = fn ($items) => $items->reduce(fn ($carry, $a) => Money::add($carry, $a->current_balance), '0');

        return [
            'all' => $sum($active),
            'cash' => $sum($active->where('type', 'cash')),
            'bank' => $sum($active->where('type', 'bank')),
            'wallet' => $sum($active->where('type', 'wallet')),
        ];
    }

    #[On('money-moved')]
    public function refresh(): void
    {
        unset($this->accounts, $this->recent, $this->totals);
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'accountTitle', 'accountNumber', 'openingBalance');
        $this->type = 'bank';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $account = Account::query()->findOrFail($id);

        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->type = $account->type;
        $this->accountTitle = (string) $account->account_title;
        $this->accountNumber = (string) $account->account_number;
        $this->openingBalance = '';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(AccountLedger $ledger): void
    {
        $editing = $this->editingId ? Account::query()->findOrFail($this->editingId) : null;

        $this->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('accounts', 'name')->where('tenant_id', $this->shop->id)->ignore($editing?->id)],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'accountTitle' => ['nullable', 'string', 'max:120'],
            'accountNumber' => ['nullable', 'string', 'max:60'],
            'openingBalance' => ['nullable', 'numeric', 'between:-99999999,99999999'],
        ], [
            'name.unique' => 'You already have an account with this name.',
        ], [
            'accountTitle' => 'account title',
            'accountNumber' => 'account number / IBAN',
            'openingBalance' => 'opening balance',
        ]);

        $attributes = [
            'name' => trim($this->name),
            // The default Cash Drawer always stays a cash account.
            'type' => $editing?->is_default_cash ? 'cash' : $this->type,
            'account_title' => $this->accountTitle ?: null,
            'account_number' => $this->accountNumber ?: null,
        ];

        if ($editing) {
            $editing->update($attributes);
            $message = 'Account updated.';
        } else {
            DB::transaction(function () use ($attributes, $ledger) {
                $opening = Money::of($this->openingBalance);
                $account = Account::create($attributes + [
                    'opening_balance' => $opening,
                    'sort_order' => (int) Account::query()->max('sort_order') + 1,
                ]);

                if (! Money::isZero($opening)) {
                    $ledger->post($account, 'opening', $opening, note: 'Opening balance');
                }
            });
            $message = 'Account added.';
        }

        $this->showModal = false;
        unset($this->accounts, $this->recent, $this->totals);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function toggleActive(int $id): void
    {
        $account = Account::query()->findOrFail($id);

        if ($account->is_default_cash) {
            $this->dispatch('toast', type: 'error', message: 'The Cash Drawer is always active.');

            return;
        }

        $account->update(['is_active' => ! $account->is_active]);
        unset($this->accounts, $this->totals);
        $this->dispatch('toast', type: 'success', message: $account->name.($account->is_active ? ' is active again.' : ' is hidden at checkout.'));
    }

    public function delete(int $id): void
    {
        $account = Account::query()->withCount('transactions')->findOrFail($id);

        if ($account->is_default_cash || $account->transactions_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'Accounts with transactions cannot be deleted. Deactivate it instead.');

            return;
        }

        $account->delete();
        unset($this->accounts, $this->totals);
        $this->dispatch('toast', type: 'success', message: 'Account deleted.');
    }
};
?>

<div>
    <x-page-header title="Accounts" subtitle="Where your money goes: the cash drawer, bank accounts and mobile wallets.">
        <x-slot:actions>
            @if ($this->accounts->where('is_active', true)->count() > 1)
                <button type="button" class="btn btn-light btn-touch" wire:click="$dispatch('move-money', { mode: 'transfer' })">
                    <i class="ri-arrow-left-right-line"></i>Transfer
                </button>
            @endif
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate">
                <i class="ri-add-line"></i>Add account
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-kpi label="All accounts" :value="$this->shop->money($this->totals['all'])" icon="ri-wallet-3-line" tone="primary" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Cash" :value="$this->shop->money($this->totals['cash'])" icon="ri-safe-2-line" tone="success" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Banks" :value="$this->shop->money($this->totals['bank'])" icon="ri-bank-line" tone="info" /></div>
        <div class="col-6 col-xl-3"><x-kpi label="Wallets" :value="$this->shop->money($this->totals['wallet'])" icon="ri-smartphone-line" tone="warning" /></div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($this->accounts as $account)
            <div class="col-md-6 col-xxl-4" wire:key="account-{{ $account->id }}">
                <div class="card h-100 mb-0 {{ $account->is_active ? '' : 'opacity-75' }}">
                    <div class="card-body">
                        <div class="hstack gap-3 align-items-start mb-3">
                            <span class="avatar avatar-md avatar-title rounded-3 bg-{{ $account->tone() }}-subtle text-{{ $account->tone() }} border-0 fs-20">
                                <i class="{{ $account->icon() }}"></i>
                            </span>
                            <div class="min-w-0 flex-grow-1">
                                <a href="{{ route('shop.accounts.show', ['account' => $account->id]) }}" wire:navigate class="fw-semibold text-truncate d-block text-reset">{{ $account->name }}</a>
                                <div class="fs-12 text-muted">
                                    {{ $account->typeLabel() }}
                                    @if ($account->is_default_cash) &middot; <span class="text-primary">Default</span> @endif
                                    @unless ($account->is_active) &middot; <span class="text-danger">Inactive</span> @endunless
                                </div>
                            </div>
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="Account actions">
                                    <i class="ri-more-2-fill"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a href="{{ route('shop.accounts.show', ['account' => $account->id]) }}" wire:navigate class="dropdown-item"><i class="ri-file-list-3-line me-2"></i>Ledger</a>
                                    @if ($account->is_active)
                                        <button type="button" class="dropdown-item" wire:click="$dispatch('move-money', { mode: 'in', account: {{ $account->id }} })"><i class="ri-add-line me-2"></i>Money in</button>
                                        <button type="button" class="dropdown-item" wire:click="$dispatch('move-money', { mode: 'out', account: {{ $account->id }} })"><i class="ri-subtract-line me-2"></i>Money out</button>
                                    @endif
                                    <div class="dropdown-divider"></div>
                                    <button type="button" class="dropdown-item" wire:click="openEdit({{ $account->id }})"><i class="ri-pencil-line me-2"></i>Edit</button>
                                    @unless ($account->is_default_cash)
                                        <button type="button" class="dropdown-item" wire:click="toggleActive({{ $account->id }})">
                                            <i class="{{ $account->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }} me-2"></i>{{ $account->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                        @if ($account->transactions_count === 0)
                                            <button type="button" class="dropdown-item text-danger" wire:click="delete({{ $account->id }})"
                                                    wire:confirm="Delete {{ $account->name }}?"><i class="ri-delete-bin-line me-2"></i>Delete</button>
                                        @endif
                                    @endunless
                                </div>
                            </div>
                        </div>
                        <div class="fs-12 text-muted">Balance</div>
                        <div class="hstack justify-content-between align-items-end gap-2">
                            <div class="fs-4 fw-bold {{ (float) $account->current_balance < 0 ? 'text-danger' : '' }}">{{ $this->shop->money($account->current_balance) }}</div>
                            <a href="{{ route('shop.accounts.show', ['account' => $account->id]) }}" wire:navigate class="btn btn-sm btn-light flex-shrink-0">Ledger <i class="ri-arrow-right-s-line"></i></a>
                        </div>
                        @if ($account->account_title || $account->account_number)
                            <div class="fs-13 text-muted mt-2 text-truncate">
                                {{ $account->account_title }}@if ($account->account_title && $account->account_number) &middot; @endif<span class="sfms-mono">{{ $account->account_number }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mb-0">
        <div class="card-header"><h5 class="card-title mb-0">Recent activity</h5></div>
        <div class="card-body p-0">
            @if ($this->recent->isEmpty())
                <x-empty-state icon="ri-exchange-dollar-line" title="No transactions yet">
                    Sales, transfers and expenses will appear here.
                </x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Date</th><th>Account</th><th>Type</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->recent as $txn)
                                <tr wire:key="txn-{{ $txn->id }}">
                                    <td data-label="Date">{{ $txn->transacted_at->timezone($this->shop->timezone)->format('d M Y, h:i A') }}</td>
                                    <td data-label="Account">
                                        @if ($txn->account)
                                            <a href="{{ route('shop.accounts.show', ['account' => $txn->account_id]) }}" wire:navigate>{{ $txn->account->name }}</a>
                                        @endif
                                    </td>
                                    <td data-label="Type">{{ $txn->typeLabel() }}@if ($txn->note)<div class="fs-12 text-muted">{{ $txn->note }}</div>@endif</td>
                                    <td data-label="Amount" class="text-end fw-semibold {{ (float) $txn->amount < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ (float) $txn->amount < 0 ? '−' : '+' }}{{ $this->shop->money(abs((float) $txn->amount)) }}
                                    </td>
                                    <td data-label="Balance" class="text-end">{{ $this->shop->money($txn->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <livewire:accounts.move-money />

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit account' : 'Add account'">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                <x-input model="name" label="Name" required placeholder="e.g. Meezan Bank, JazzCash" />
                @php($isCashDrawer = $editingId && $this->accounts->firstWhere('id', $editingId)?->is_default_cash)
                <x-select model="type" label="Type" :options="\App\Models\Account::TYPES" :disabled="$isCashDrawer" />
                <x-input model="accountTitle" label="Account title" placeholder="Name on the account" />
                <x-input model="accountNumber" label="Account number / IBAN / wallet number" />
                @unless ($editingId)
                    <x-input model="openingBalance" label="Opening balance" inputmode="decimal" :prepend="$this->shop->currency_symbol"
                             placeholder="0" hint="Money already in this account today." />
                @endunless
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save</button>
            </div>
        </form>
    </x-modal>
</div>
