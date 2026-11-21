<?php

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::admin')] #[Title('Shops')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['active', 'expiring', 'expired', 'suspended'], true) ? $status : '';
        $this->resetPage();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            '' => Shop::query()->count(),
            'active' => Shop::query()->withStatus('active')->count(),
            'expiring' => Shop::query()->withStatus('expiring')->count(),
            'expired' => Shop::query()->withStatus('expired')->count(),
            'suspended' => Shop::query()->withStatus('suspended')->count(),
        ];
    }

    #[Computed]
    public function shops()
    {
        $term = trim($this->search);

        return Shop::query()
            ->with('owner')
            ->withCount('users')
            ->withStatus($this->status ?: null)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('prefix', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhereHas('users', fn (Builder $u) => $u->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))))
            ->orderBy('name')
            ->paginate(15);
    }
};
?>

<div>
    <x-page-header title="Shops" :breadcrumbs="['Dashboard' => route('admin.dashboard'), 'Shops' => null]">
        <x-slot:actions>
            <a href="{{ route('admin.shops.create') }}" class="btn btn-primary btn-touch" wire:navigate>
                <i class="ri-add-line"></i>New shop
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-0">
        <div class="card-header vstack gap-3">
            <div class="sfms-filter-tabs" role="tablist" aria-label="Filter by status">
                @foreach (['' => 'All', 'active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired', 'suspended' => 'Suspended'] as $key => $label)
                    <button type="button" wire:click="setStatus('{{ $key }}')" role="tab" aria-selected="{{ $status === $key ? 'true' : 'false' }}"
                            class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-light' }}">
                        {{ $label }}
                        <span class="badge rounded-pill ms-1 {{ $status === $key ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary' }}">{{ $this->counts[$key] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="form-icon">
                <input type="search" wire:model.live.debounce.300ms="search" class="form-control form-control-icon form-control-touch"
                       placeholder="Search by shop name, prefix, phone or owner" aria-label="Search shops">
                <i class="ri-search-2-line text-muted"></i>
            </div>
        </div>

        <div class="card-body p-0">
            @if ($this->shops->isEmpty())
                <x-empty-state icon="ri-store-2-line" title="No shops found">
                    @if ($search !== '' || $status !== '')
                        Try a different search or filter.
                    @else
                        <a href="{{ route('admin.shops.create') }}" wire:navigate>Create the first shop</a>
                    @endif
                </x-empty-state>
            @else
                <div class="table-responsive" wire:loading.class="opacity-50" wire:target="search,setStatus,gotoPage,nextPage,previousPage">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr>
                                <th>Shop</th>
                                <th>Owner</th>
                                <th>Paid until</th>
                                <th>Inventory</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->shops as $shop)
                                @php($badge = $shop->statusBadge())
                                <tr wire:key="shop-{{ $shop->id }}">
                                    <td class="td-primary">
                                        <div class="hstack gap-3 min-w-0">
                                            <span class="avatar avatar-sm avatar-title rounded-2 bg-primary-subtle text-primary fw-semibold fs-12 border-0 flex-shrink-0">
                                                {{ $shop->initials() }}
                                            </span>
                                            <div class="min-w-0">
                                                <a href="{{ route('admin.shops.show', $shop) }}" class="fw-semibold text-reset" wire:navigate>{{ $shop->name }}</a>
                                                <div class="fs-12 text-muted sfms-mono">/{{ $shop->prefix }} &middot; {{ $shop->order_prefix }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Owner">
                                        <div class="cell-stack">
                                            <div>{{ $shop->owner?->name ?? '—' }}</div>
                                            <div class="fs-12 text-muted">{{ $shop->owner?->loginName() }} &middot; {{ $shop->users_count }} {{ Str::plural('user', $shop->users_count) }}</div>
                                        </div>
                                    </td>
                                    <td data-label="Paid until">
                                        <div class="cell-stack">
                                        @if ($shop->subscription_ends_at)
                                            <div>{{ $shop->subscription_ends_at->format('d M Y') }}</div>
                                            @php($days = $shop->daysUntilExpiry())
                                            <div class="fs-12 {{ $days < 0 ? 'text-danger' : ($days <= \App\Models\Shop::EXPIRY_WARNING_DAYS ? 'text-warning' : 'text-muted') }}">
                                                {{ $days < 0 ? abs($days).' '.Str::plural('day', abs($days)).' ago' : ($days === 0 ? 'Today' : $days.' '.Str::plural('day', $days).' left') }}
                                            </div>
                                        @else
                                            <span class="text-muted">No end date</span>
                                        @endif
                                        </div>
                                    </td>
                                    <td data-label="Inventory">
                                        <span class="badge {{ $shop->inventory_tracking_enabled ? 'bg-info-subtle text-info' : 'bg-light text-muted' }}">
                                            {{ $shop->inventory_tracking_enabled ? 'On' : 'Off' }}
                                        </span>
                                    </td>
                                    <td data-label="Status"><span class="badge {{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                                    <td class="text-end td-actions">
                                        <div class="hstack gap-2 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light" data-copy="{{ $shop->loginUrl() }}" title="Copy staff sign-in link" aria-label="Copy staff sign-in link">
                                                <i class="ri-link"></i>
                                            </button>
                                            <a href="{{ route('admin.shops.show', $shop) }}" class="btn btn-sm btn-primary" wire:navigate>Manage</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($this->shops->hasPages())
            <div class="card-footer">
                {{ $this->shops->links() }}
            </div>
        @endif
    </div>
</div>
