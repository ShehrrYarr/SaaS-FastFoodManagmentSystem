<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::admin')] #[Title('Dashboard')] class extends Component
{
    #[Computed]
    public function counts(): array
    {
        return [
            'total' => Shop::query()->count(),
            'active' => Shop::query()->withStatus('active')->count(),
            'expiring' => Shop::query()->withStatus('expiring')->count(),
            'expired' => Shop::query()->withStatus('expired')->count(),
            'suspended' => Shop::query()->withStatus('suspended')->count(),
            'staff' => User::query()->whereNotNull('tenant_id')->where('is_active', true)->count(),
        ];
    }

    /** Suspended, expired or expiring within the warning window. */
    #[Computed]
    public function needsAttention()
    {
        $warnUntil = Carbon::now(Shop::DEFAULT_TIMEZONE)->addDays(Shop::EXPIRY_WARNING_DAYS)->toDateString();

        return Shop::query()
            ->where(fn ($q) => $q->where('is_active', false)->orWhereDate('subscription_ends_at', '<=', $warnUntil))
            ->orderBy('is_active')
            ->orderBy('subscription_ends_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function recentShops()
    {
        return Shop::query()->with('owner')->latest()->limit(6)->get();
    }
};
?>

<div>
    <x-page-header title="Dashboard" subtitle="All shops on the platform at a glance.">
        <x-slot:actions>
            <a href="{{ route('admin.shops.create') }}" class="btn btn-primary btn-touch" wire:navigate>
                <i class="ri-add-line"></i>New shop
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-kpi label="Total shops" :value="$this->counts['total']" icon="ri-store-2-line" tone="primary"
                   :hint="$this->counts['staff'].' active staff users'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Active" :value="$this->counts['active']" icon="ri-checkbox-circle-line" tone="success"
                   hint="Can sign in and sell" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Expiring soon" :value="$this->counts['expiring']" icon="ri-time-line" tone="warning"
                   :hint="'Within '.\App\Models\Shop::EXPIRY_WARNING_DAYS.' days'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Suspended or expired" :value="$this->counts['suspended'] + $this->counts['expired']"
                   icon="ri-forbid-2-line" tone="danger" hint="Staff cannot sign in" />
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card mb-0 h-100">
                <div class="card-header hstack justify-content-between gap-2">
                    <h5 class="card-title mb-0">Needs attention</h5>
                    <a href="{{ route('admin.shops.index', ['status' => 'expiring']) }}" class="btn btn-sm btn-light" wire:navigate>View all</a>
                </div>
                <div class="card-body p-0 p-md-0">
                    @if ($this->needsAttention->isEmpty())
                        <x-empty-state icon="ri-emotion-happy-line" title="All shops are in good standing">
                            Nothing is suspended or about to expire.
                        </x-empty-state>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 table-stack">
                                <thead class="table-light">
                                    <tr>
                                        <th>Shop</th>
                                        <th>Status</th>
                                        <th>Paid until</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->needsAttention as $shop)
                                        @php($badge = $shop->statusBadge())
                                        <tr wire:key="attention-{{ $shop->id }}">
                                            <td class="td-primary">
                                                <div class="fw-semibold">{{ $shop->name }}</div>
                                                <div class="fs-12 text-muted sfms-mono">/{{ $shop->prefix }}</div>
                                            </td>
                                            <td data-label="Status"><span class="badge {{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                                            <td data-label="Paid until">{{ $shop->subscription_ends_at?->format('d M Y') ?? '—' }}</td>
                                            <td class="text-end td-actions">
                                                <a href="{{ route('admin.shops.show', $shop) }}" class="btn btn-sm btn-light" wire:navigate>Manage</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card mb-0 h-100">
                <div class="card-header hstack justify-content-between gap-2">
                    <h5 class="card-title mb-0">Recently added</h5>
                    <a href="{{ route('admin.shops.index') }}" class="btn btn-sm btn-light" wire:navigate>All shops</a>
                </div>
                <div class="card-body">
                    @forelse ($this->recentShops as $shop)
                        <a href="{{ route('admin.shops.show', $shop) }}" wire:navigate wire:key="recent-{{ $shop->id }}"
                           class="hstack gap-3 text-reset text-decoration-none py-2 @unless ($loop->last) border-bottom @endunless">
                            <span class="avatar avatar-sm avatar-title rounded-2 bg-primary-subtle text-primary fw-semibold fs-12 border-0">
                                {{ $shop->initials() }}
                            </span>
                            <span class="min-w-0 flex-grow-1">
                                <span class="d-block fw-semibold text-truncate">{{ $shop->name }}</span>
                                <span class="d-block fs-12 text-muted text-truncate">{{ $shop->owner?->name ?? 'No owner' }} &middot; {{ $shop->created_at->diffForHumans() }}</span>
                            </span>
                            <i class="ri-arrow-right-s-line text-muted"></i>
                        </a>
                    @empty
                        <x-empty-state icon="ri-store-2-line" title="No shops yet">
                            <a href="{{ route('admin.shops.create') }}" wire:navigate>Create the first shop</a>
                        </x-empty-state>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
