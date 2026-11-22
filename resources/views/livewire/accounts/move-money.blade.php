<?php

use App\Exceptions\PosException;
use App\Models\Account;
use App\Services\AccountMoves;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Transfer between accounts, or money in / out of one account. Opened from any
 * page with $dispatch('move-money', { mode: 'transfer' | 'in' | 'out', account: id }).
 * Tells the page with a 'money-moved' event so it can refresh its figures.
 */
new class extends Component
{
    public bool $show = false;

    public string $mode = 'transfer';

    public string $fromId = '';

    public string $toId = '';

    public string $accountId = '';

    public string $amount = '';

    public string $note = '';

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function accounts()
    {
        return Account::query()->active()->ordered()->get();
    }

    /** "Cash Drawer · Rs 12,400" */
    public function options(): array
    {
        return $this->accounts->mapWithKeys(fn (Account $a) => [$a->id => $a->name.' · '.$this->shop->money($a->current_balance)])->all();
    }

    #[On('move-money')]
    public function start(string $mode = 'transfer', ?int $account = null): void
    {
        unset($this->accounts);
        $this->mode = in_array($mode, ['transfer', 'in', 'out'], true) ? $mode : 'transfer';
        $this->reset('amount', 'note', 'toId');
        $this->resetValidation();

        $chosen = $this->accounts->firstWhere('id', $account) ?? $this->accounts->firstWhere('is_default_cash', true) ?? $this->accounts->first();

        if ($this->mode === 'transfer') {
            $this->fromId = (string) $chosen?->id;
            $other = $this->accounts->first(fn (Account $a) => $a->id !== $chosen?->id);
            $this->toId = $other ? (string) $other->id : '';
        } else {
            $this->accountId = (string) $chosen?->id;
        }

        $this->show = true;
    }

    public function save(AccountMoves $moves): void
    {
        $transfer = $this->mode === 'transfer';

        $this->validate([
            'fromId' => $transfer ? ['required', 'integer', 'different:toId'] : ['nullable'],
            'toId' => $transfer ? ['required', 'integer'] : ['nullable'],
            'accountId' => $transfer ? ['nullable'] : ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'note' => [$transfer ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'fromId.different' => 'Choose two different accounts.',
        ], [
            'fromId' => 'from account',
            'toId' => 'to account',
            'accountId' => 'account',
            'note' => $transfer ? 'note' : 'reason',
        ]);

        try {
            if ($transfer) {
                $done = $moves->transfer((int) $this->fromId, (int) $this->toId, $this->amount, $this->note);
                $message = 'Moved '.$this->shop->money($done->amount).' from '.$done->fromAccount->name.' to '.$done->toAccount->name.'.';
            } else {
                $done = $moves->adjust((int) $this->accountId, $this->mode, $this->amount, $this->note);
                $message = $this->shop->money(abs((float) $done->amount)).($this->mode === 'in' ? ' added to ' : ' taken out of ').$done->account->name.'.';
            }
        } catch (PosException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->show = false;
        unset($this->accounts);
        $this->dispatch('money-moved');
        $this->dispatch('toast', type: 'success', message: $message);
    }
};
?>

<div>
    <x-modal wire:model="show" :title="match ($mode) { 'in' => 'Money in', 'out' => 'Money out', default => 'Transfer between accounts' }">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                @if ($mode === 'transfer')
                    <p class="text-muted fs-13 mb-0">For example, banking the day's cash or moving wallet money to the bank.</p>
                    <x-select model="fromId" id="mm-from" label="From" :options="$this->options()" required />
                    <div class="text-center text-muted lh-1" aria-hidden="true"><i class="ri-arrow-down-line fs-20"></i></div>
                    <x-select model="toId" id="mm-to" label="To" :options="$this->options()" placeholder="Choose an account" required />
                @else
                    <p class="text-muted fs-13 mb-0">
                        @if ($mode === 'in')
                            Money that isn't a sale, e.g. the owner tops up the float or deposits cash.
                        @else
                            Money that isn't an expense, e.g. the owner takes cash home or the bank deducts a charge.
                        @endif
                    </p>
                    <x-select model="accountId" id="mm-account" label="Account" :options="$this->options()" required />
                @endif

                <x-input model="amount" id="mm-amount" label="Amount" inputmode="decimal" :prepend="$this->shop->currency_symbol" required placeholder="0" />

                <x-input model="note" id="mm-note" :label="$mode === 'transfer' ? 'Note (optional)' : 'Reason'" :required="$mode !== 'transfer'"
                         :placeholder="match ($mode) { 'in' => 'e.g. Float from owner', 'out' => 'e.g. Owner drawing', default => 'e.g. Deposit slip 4471' }" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">
                    <i class="{{ $mode === 'transfer' ? 'ri-arrow-left-right-line' : ($mode === 'in' ? 'ri-add-line' : 'ri-subtract-line') }}"></i>
                    {{ match ($mode) { 'in' => 'Add money', 'out' => 'Take money out', default => 'Transfer' } }}
                </button>
            </div>
        </form>
    </x-modal>
</div>
