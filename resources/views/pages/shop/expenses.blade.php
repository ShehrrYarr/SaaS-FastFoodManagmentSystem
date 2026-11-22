<?php

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Expenses;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Expenses')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $search = '';

    // Add / edit
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $categoryId = '';
    public string $accountId = '';
    public string $amount = '';
    public string $spentOn = '';
    public string $payee = '';
    public string $note = '';
    public ?string $formError = null;

    // Void
    public bool $showVoid = false;
    public ?int $voidId = null;
    public string $voidReason = '';

    // Categories
    public bool $showCategories = false;
    public string $newCategory = '';
    public ?int $renamingId = null;
    public string $renameTo = '';

    public function updated(string $property): void
    {
        if ($property === 'month' && $this->month === $this->shop->today()->format('Y-m')) {
            $this->month = '';
        }

        if (in_array($property, ['month', 'category', 'account', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('month', 'category', 'account', 'search');
        $this->resetPage();
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    /** The month shown: ?month=2026-11, or this month. */
    #[Computed]
    public function period(): array
    {
        $today = $this->shop->today();
        $start = preg_match('/^\d{4}-\d{2}$/', $this->month) ? Carbon::createFromFormat('!Y-m', $this->month, $this->shop->timezone) : $today->copy()->startOfMonth();

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->endOfMonth()->toDateString(),
            'label' => $start->format('F Y'),
            'current' => $start->isSameMonth($today),
            'value' => $start->format('Y-m'),
        ];
    }

    /** The last 12 months for the month picker. */
    public function months(): array
    {
        $first = $this->shop->today()->startOfMonth();

        return collect(range(0, 11))->mapWithKeys(fn ($i) => [
            $first->copy()->subMonths($i)->format('Y-m') => $first->copy()->subMonths($i)->format('F Y'),
        ])->all();
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::query()->ordered()->withCount('expenses')->get();
    }

    #[Computed]
    public function accounts()
    {
        return Account::query()->ordered()->get();
    }

    /** Filters shared by the list and the totals. A search looks across every month. */
    private function filtered()
    {
        return Expense::query()
            ->when($this->search === '', fn ($q) => $q->whereBetween('spent_on', [$this->period['start'], $this->period['end']]))
            ->when($this->category !== '', fn ($q) => $q->where('expense_category_id', (int) $this->category))
            ->when($this->account !== '', fn ($q) => $q->where('account_id', (int) $this->account))
            ->search($this->search);
    }

    #[Computed]
    public function expenses()
    {
        return $this->filtered()->with(['category', 'account', 'createdBy', 'voidedBy'])
            ->latest('spent_on')->latest('id')->paginate(25);
    }

    #[Computed]
    public function totals(): array
    {
        $counted = $this->filtered()->counted();
        $drawerId = $this->accounts->firstWhere('is_default_cash', true)?->id;

        $byCategory = (clone $counted)
            ->selectRaw('expense_category_id, COUNT(*) as entries, SUM(amount) as amount')
            ->groupBy('expense_category_id')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'name' => $this->categories->firstWhere('id', $row->expense_category_id)?->name ?? '—',
                'entries' => (int) $row->entries,
                'amount' => Money::of($row->amount),
            ]);

        return [
            'amount' => Money::sum($byCategory->pluck('amount')->all()),
            'entries' => $byCategory->sum('entries'),
            'today' => Money::of((clone $counted)->whereDate('spent_on', $this->shop->today()->toDateString())->sum('amount')),
            'drawer' => Money::of($drawerId ? (clone $counted)->where('account_id', $drawerId)->sum('amount') : 0),
            'by_category' => $byCategory->all(),
        ];
    }

    // ---------------------------------------------------------------- add / edit

    public function openCreate(): void
    {
        $this->reset('editingId', 'categoryId', 'amount', 'payee', 'note', 'formError');
        $this->accountId = (string) $this->accounts->where('is_active', true)->firstWhere('is_default_cash', true)?->id;
        $this->spentOn = $this->shop->today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $expense = Expense::query()->findOrFail($id);

        $this->editingId = $expense->id;
        $this->categoryId = (string) $expense->expense_category_id;
        $this->accountId = (string) $expense->account_id;
        $this->amount = (string) $expense->amount;
        $this->spentOn = $expense->spent_on->toDateString();
        $this->payee = (string) $expense->payee;
        $this->note = (string) $expense->note;
        $this->formError = null;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(Expenses $service): void
    {
        $editing = $this->editingId !== null;

        $this->validate([
            'categoryId' => ['required', 'integer'],
            'accountId' => $editing ? ['nullable'] : ['required', 'integer'],
            'amount' => $editing ? ['nullable'] : ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'spentOn' => ['required', 'date', 'before_or_equal:'.$this->shop->today()->toDateString()],
            'payee' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'spentOn.before_or_equal' => 'The date cannot be in the future.',
        ], [
            'categoryId' => 'category',
            'accountId' => 'paid from',
            'spentOn' => 'date',
            'payee' => 'paid to',
        ]);

        $data = [
            'expense_category_id' => $this->categoryId,
            'account_id' => $this->accountId,
            'amount' => $this->amount,
            'spent_on' => $this->spentOn,
            'payee' => $this->payee,
            'note' => $this->note,
        ];

        try {
            if ($editing) {
                $service->updateDetails(Expense::query()->findOrFail($this->editingId), $data);
                $message = 'Expense updated.';
            } else {
                $expense = $service->record($data);
                $message = $expense->voucher_number.': '.$this->shop->money($expense->amount).' paid from '.$expense->account->name.'.';
            }
        } catch (PosException $e) {
            $this->formError = $e->getMessage();

            return;
        }

        $this->showForm = false;
        $this->refreshLists();
        $this->dispatch('toast', type: 'success', message: $message);
    }

    // ---------------------------------------------------------------- void

    public function startVoid(int $id): void
    {
        $this->voidId = Expense::query()->counted()->findOrFail($id)->id;
        $this->voidReason = '';
        $this->resetValidation();
        $this->showVoid = true;
    }

    public function confirmVoid(Expenses $service): void
    {
        $this->validate(['voidReason' => ['required', 'string', 'max:255']], [], ['voidReason' => 'reason']);

        try {
            $expense = $service->void(Expense::query()->findOrFail($this->voidId), $this->voidReason);
        } catch (PosException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }

        $this->showVoid = false;
        $this->refreshLists();
        $this->dispatch('toast', type: 'success', message: $expense->voucher_number.' voided; '.$this->shop->money($expense->amount).' is back in '.$expense->account->name.'.');
    }

    // ---------------------------------------------------------------- categories

    public function addCategory(): void
    {
        $this->newCategory = trim($this->newCategory);
        $this->validate(['newCategory' => ['required', 'string', 'max:60',
            Rule::unique('expense_categories', 'name')->where('tenant_id', $this->shop->id)]],
            ['newCategory.unique' => 'That category already exists.'], ['newCategory' => 'name']);

        ExpenseCategory::create(['name' => $this->newCategory, 'sort_order' => (int) ExpenseCategory::query()->max('sort_order') + 1]);
        $this->newCategory = '';
        unset($this->categories);
    }

    public function startRename(int $id): void
    {
        $this->renamingId = $id;
        $this->renameTo = (string) $this->categories->firstWhere('id', $id)?->name;
        $this->resetValidation();
    }

    public function saveRename(): void
    {
        $category = ExpenseCategory::query()->findOrFail($this->renamingId);
        $this->renameTo = trim($this->renameTo);
        $this->validate(['renameTo' => ['required', 'string', 'max:60',
            Rule::unique('expense_categories', 'name')->where('tenant_id', $this->shop->id)->ignore($category->id)]],
            ['renameTo.unique' => 'That category already exists.'], ['renameTo' => 'name']);

        $category->update(['name' => $this->renameTo]);
        $this->renamingId = null;
        unset($this->categories, $this->totals);
    }

    public function toggleCategory(int $id): void
    {
        $category = ExpenseCategory::query()->findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
        unset($this->categories);
    }

    public function deleteCategory(int $id): void
    {
        $category = ExpenseCategory::query()->withCount('expenses')->findOrFail($id);

        if ($category->expenses_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'This category has expenses. Hide it instead.');

            return;
        }

        $category->delete();
        unset($this->categories);
    }

    private function refreshLists(): void
    {
        unset($this->expenses, $this->totals, $this->categories, $this->accounts);
    }
};
?>

@php
    $shop = $this->shop;
    $period = $this->period;
    $totals = $this->totals;
    $filtered = $this->category !== '' || $this->account !== '' || $this->search !== '';
    $largest = (float) ($totals['by_category'][0]['amount'] ?? 0);
@endphp

<div>
    <x-page-header title="Expenses" subtitle="Rent, salaries, bills: money spent on running the shop.">
        <x-slot:actions>
            <button type="button" class="btn btn-light btn-touch" wire:click="$set('showCategories', true)"><i class="ri-price-tag-3-line"></i>Categories</button>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-add-line"></i>Add expense</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-kpi :label="$this->search !== '' ? 'Matching expenses' : ($period['current'] ? 'This month' : $period['label'])" :value="$shop->money($totals['amount'])"
                   icon="ri-wallet-3-line" tone="danger" :hint="$totals['entries'].' '.\Illuminate\Support\Str::plural('expense', $totals['entries'])" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Today" :value="$shop->money($totals['today'])" icon="ri-calendar-event-line" tone="warning" :hint="$period['current'] ? null : 'Not in this month'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Biggest category" :value="$totals['by_category'][0]['name'] ?? '—'" icon="ri-pie-chart-2-line" tone="primary"
                   :hint="isset($totals['by_category'][0]) ? $shop->money($totals['by_category'][0]['amount']) : null" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Paid from the drawer" :value="$shop->money($totals['drawer'])" icon="ri-safe-2-line" tone="success" hint="Counts in the day's cash" />
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8 order-2 order-xl-1">
            <div class="card mb-0">
                <div class="card-header">
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <select class="form-select form-select-touch" wire:model.live="month" aria-label="Month" @disabled($this->search !== '')>
                                @foreach ($this->months() as $value => $label)
                                    <option value="{{ $value === $this->shop->today()->format('Y-m') ? '' : $value }}">{{ $label }}</option>
                                @endforeach
                                @unless (array_key_exists($period['value'], $this->months()))
                                    <option value="{{ $this->month }}">{{ $period['label'] }}</option>
                                @endunless
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <select class="form-select form-select-touch" wire:model.live="category" aria-label="Category">
                                <option value="">All categories</option>
                                @foreach ($this->categories as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <select class="form-select form-select-touch" wire:model.live="account" aria-label="Paid from">
                                <option value="">All accounts</option>
                                @foreach ($this->accounts as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-search-line"></i></span>
                                <input type="search" class="form-control form-control-touch" wire:model.live.debounce.400ms="search" placeholder="Voucher, paid to, note" aria-label="Search">
                            </div>
                        </div>
                    </div>
                    @if ($this->search !== '')
                        <div class="fs-12 text-muted mt-2">Searching every month. <button type="button" class="btn btn-link btn-sm p-0 align-baseline" wire:click="clearFilters">Clear</button></div>
                    @endif
                </div>
                <div class="card-body p-0">
                    @if ($this->expenses->isEmpty())
                        <x-empty-state icon="ri-wallet-3-line" title="No expenses">
                            {{ $filtered ? 'Nothing matches these filters.' : 'Nothing recorded for '.$period['label'].'.' }}
                        </x-empty-state>
                    @else
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 table-stack">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Expense</th><th>Paid from</th><th class="text-end">Amount</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->expenses as $expense)
                                        <tr wire:key="exp-{{ $expense->id }}" @class(['opacity-50' => $expense->isVoided()])>
                                            <td data-label="Date" class="text-nowrap">
                                                {{ $expense->spent_on->format('d M Y') }}
                                                <div class="fs-12 text-muted sfms-mono">{{ $expense->voucher_number }}</div>
                                            </td>
                                            <td class="td-primary">
                                                <div class="fw-medium">
                                                    {{ $expense->category?->name }}
                                                    @if ($expense->isVoided())<span class="badge bg-danger-subtle text-danger ms-1">Voided</span>@endif
                                                </div>
                                                <div class="fs-12 text-muted">
                                                    {{ collect([$expense->payee, $expense->note])->filter()->implode(' · ') ?: 'No details' }}
                                                    &middot; {{ $expense->createdBy?->name ?? '—' }}
                                                </div>
                                                @if ($expense->isVoided())
                                                    <div class="fs-12 text-danger">Voided by {{ $expense->voidedBy?->name ?? '—' }}: {{ $expense->void_reason }}</div>
                                                @endif
                                            </td>
                                            <td data-label="Paid from">{{ $expense->account?->name }}</td>
                                            <td data-label="Amount" class="text-end fw-semibold text-nowrap @if ($expense->isVoided()) text-decoration-line-through @endif">{{ $shop->money($expense->amount) }}</td>
                                            <td class="td-actions text-end">
                                                @unless ($expense->isVoided())
                                                    <div class="dropdown">
                                                        <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="Expense actions"><i class="ri-more-2-fill"></i></button>
                                                        <div class="dropdown-menu dropdown-menu-end">
                                                            <button type="button" class="dropdown-item" wire:click="openEdit({{ $expense->id }})"><i class="ri-pencil-line me-2"></i>Edit details</button>
                                                            <button type="button" class="dropdown-item text-danger" wire:click="startVoid({{ $expense->id }})"><i class="ri-close-circle-line me-2"></i>Void</button>
                                                        </div>
                                                    </div>
                                                @endunless
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($this->expenses->hasPages())
                            <div class="p-3 border-top">{{ $this->expenses->links() }}</div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4 order-1 order-xl-2">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">By category</h5></div>
                <div class="card-body">
                    @forelse ($totals['by_category'] as $row)
                        <div class="mb-3">
                            <div class="hstack justify-content-between fs-13 mb-1">
                                <span class="fw-medium text-truncate me-2">{{ $row['name'] }} <span class="text-muted fw-normal">&times;{{ $row['entries'] }}</span></span>
                                <span class="fw-semibold text-nowrap">{{ $shop->money($row['amount']) }}</span>
                            </div>
                            <div class="sfms-meter" role="img" aria-label="{{ $row['name'] }}: {{ $shop->money($row['amount']) }}">
                                <span style="width: {{ $largest > 0 ? max(2, round((float) $row['amount'] / $largest * 100, 1)) : 0 }}%"></span>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No expenses {{ $this->search !== '' ? 'match' : 'in '.$period['label'] }}.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Add / edit --}}
    <x-modal wire:model="showForm" :title="$editingId ? 'Edit expense' : 'Add expense'">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                @if ($formError)
                    <div class="alert alert-danger mb-0 fs-13"><i class="ri-error-warning-line me-1"></i>{{ $formError }}</div>
                @endif

                <x-select model="categoryId" label="Spent on" placeholder="Choose a category" required>
                    @foreach ($this->categories as $option)
                        @if ($option->is_active || (string) $option->id === $categoryId)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endif
                    @endforeach
                </x-select>

                @if ($editingId)
                    <div class="bg-light rounded-3 px-3 py-2 fs-13">
                        <div class="hstack justify-content-between"><span class="text-muted">Amount</span><span class="fw-semibold">{{ $shop->money($amount) }}</span></div>
                        <div class="hstack justify-content-between"><span class="text-muted">Paid from</span><span>{{ $this->accounts->firstWhere('id', (int) $accountId)?->name }}</span></div>
                        <div class="text-muted fs-12 mt-1">To change the amount or account, void this expense and add it again.</div>
                    </div>
                @else
                    <x-input model="amount" label="Amount" inputmode="decimal" :prepend="$shop->currency_symbol" required placeholder="0" />
                    <x-select model="accountId" label="Paid from" required>
                        @foreach ($this->accounts->where('is_active', true) as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} · {{ $shop->money($option->current_balance) }}</option>
                        @endforeach
                    </x-select>
                @endif

                <x-input model="spentOn" type="date" label="Date" required :max="$shop->today()->toDateString()" />
                <x-input model="payee" label="Paid to (optional)" placeholder="e.g. K-Electric, landlord, plumber" />
                <x-input model="note" label="Note (optional)" placeholder="e.g. November bill, ref 55102" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save' : 'Add expense' }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Void --}}
    <x-modal wire:model="showVoid" title="Void expense">
        <form wire:submit="confirmVoid" novalidate>
            <div class="modal-body vstack gap-3">
                @php
                    $voiding = $voidId ? \App\Models\Expense::query()->with('account')->find($voidId) : null;
                @endphp
                @if ($voiding)
                    <p class="mb-0">
                        <span class="sfms-mono">{{ $voiding->voucher_number }}</span>: {{ $shop->money($voiding->amount) }} goes back into
                        <strong>{{ $voiding->account?->name }}</strong>. The expense stays on file, marked voided.
                    </p>
                @endif
                <x-input model="voidReason" label="Reason" required placeholder="e.g. Entered twice" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-touch" wire:loading.attr="disabled" wire:target="confirmVoid">Void expense</button>
            </div>
        </form>
    </x-modal>

    {{-- Categories --}}
    <x-modal wire:model="showCategories" title="Expense categories">
        <div class="modal-body">
            <form wire:submit="addCategory" class="mb-3" novalidate>
                <div class="input-group">
                    <input type="text" class="form-control form-control-touch @error('newCategory') is-invalid @enderror" wire:model="newCategory" placeholder="New category" aria-label="New category">
                    <button type="submit" class="btn btn-primary btn-touch"><i class="ri-add-line"></i>Add</button>
                </div>
                @error('newCategory')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </form>

            <ul class="list-group">
                @foreach ($this->categories as $cat)
                    <li class="list-group-item" wire:key="cat-{{ $cat->id }}">
                        @if ($renamingId === $cat->id)
                            <form wire:submit="saveRename" class="hstack gap-2" novalidate>
                                <input type="text" class="form-control form-control-sm @error('renameTo') is-invalid @enderror" wire:model="renameTo" aria-label="Category name" autofocus>
                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="$set('renamingId', null)">Cancel</button>
                            </form>
                            @error('renameTo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @else
                            <div class="hstack gap-2">
                                <span class="flex-grow-1 {{ $cat->is_active ? '' : 'text-muted text-decoration-line-through' }}">{{ $cat->name }}</span>
                                <span class="badge bg-light text-muted">{{ $cat->expenses_count }}</span>
                                <button type="button" class="btn btn-sm btn-light" wire:click="startRename({{ $cat->id }})" aria-label="Rename {{ $cat->name }}"><i class="ri-pencil-line"></i></button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="toggleCategory({{ $cat->id }})" aria-label="{{ $cat->is_active ? 'Hide' : 'Show' }} {{ $cat->name }}" title="{{ $cat->is_active ? 'Hide from the list' : 'Show again' }}">
                                    <i class="{{ $cat->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }}"></i>
                                </button>
                                @if ($cat->expenses_count === 0)
                                    <button type="button" class="btn btn-sm btn-light text-danger" wire:click="deleteCategory({{ $cat->id }})" wire:confirm="Delete {{ $cat->name }}?" aria-label="Delete {{ $cat->name }}"><i class="ri-delete-bin-line"></i></button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Done</button>
        </div>
    </x-modal>
</div>
