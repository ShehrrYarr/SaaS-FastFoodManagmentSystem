<?php

use App\Models\Shop;
use App\Models\ShopSequence;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::shop')] #[Title('Dashboard')] class extends Component
{
    #[Computed]
    public function shop(): Shop
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function staffCount(): int
    {
        return User::ofShop($this->shop)->where('is_active', true)->count();
    }

    public function greeting(): string
    {
        $hour = now($this->shop->timezone)->hour;
        $first = Str::before(auth('shop')->user()->name, ' ');

        return ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening')).', '.$first;
    }
};
?>

<div>
    <x-page-header :title="$this->greeting()" subtitle="Here is your shop at a glance." />

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-kpi label="Today's sales" :value="$this->shop->money(0)" icon="ri-money-dollar-circle-line" tone="success" hint="Live once the POS is ready" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Orders today" value="0" icon="ri-file-list-3-line" tone="primary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Open tabs" value="0" icon="ri-restaurant-2-line" tone="warning" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi label="Active staff" :value="$this->staffCount" icon="ri-team-line" tone="info" />
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-0 h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">Your workspace</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 gy-3">
                        <dt class="col-sm-4 text-muted fw-normal">Staff sign-in link</dt>
                        <dd class="col-sm-8 mb-0">
                            <div class="hstack gap-2 min-w-0">
                                <span class="sfms-mono text-truncate">{{ $this->shop->loginUrl() }}</span>
                                <button type="button" class="btn btn-sm btn-light flex-shrink-0" data-copy="{{ $this->shop->loginUrl() }}" aria-label="Copy link">
                                    <i class="ri-file-copy-line"></i>
                                </button>
                            </div>
                        </dd>

                        <dt class="col-sm-4 text-muted fw-normal">Order numbers</dt>
                        <dd class="col-sm-8 mb-0 sfms-mono">{{ \App\Models\ShopSequence::format($this->shop->order_prefix, 1) }}, {{ \App\Models\ShopSequence::format($this->shop->order_prefix, 2) }}, &hellip;</dd>

                        <dt class="col-sm-4 text-muted fw-normal">Inventory tracking</dt>
                        <dd class="col-sm-8 mb-0">
                            @if ($this->shop->inventory_tracking_enabled)
                                <span class="badge bg-success-subtle text-success">On</span>
                                <span class="text-muted fs-13 ms-1">Recipes deduct stock when items go to the kitchen.</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Off</span>
                                <span class="text-muted fs-13 ms-1">Products sell without stock tracking.</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted fw-normal">Subscription</dt>
                        <dd class="col-sm-8 mb-0">
                            @if ($this->shop->subscription_ends_at)
                                Paid until {{ $this->shop->subscription_ends_at->format('d M Y') }}
                                @php($badge = $this->shop->statusBadge())
                                <span class="badge {{ $badge['class'] }} ms-1">{{ $badge['label'] }}</span>
                            @else
                                No end date
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted fw-normal">Currency</dt>
                        <dd class="col-sm-8 mb-0">{{ $this->shop->currency }} ({{ $this->shop->currency_symbol }})</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-0 h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">Coming next</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled vstack gap-3 mb-0">
                        <li class="hstack gap-3">
                            <span class="avatar avatar-sm avatar-title rounded-2 bg-primary-subtle text-primary border-0"><i class="ri-restaurant-line"></i></span>
                            <span>Menu, tables, waiters, staff, roles and accounts</span>
                        </li>
                        <li class="hstack gap-3">
                            <span class="avatar avatar-sm avatar-title rounded-2 bg-success-subtle text-success border-0"><i class="ri-shopping-basket-2-line"></i></span>
                            <span>POS, open tabs, kitchen tickets and checkout</span>
                        </li>
                        <li class="hstack gap-3">
                            <span class="avatar avatar-sm avatar-title rounded-2 bg-warning-subtle text-warning border-0"><i class="ri-archive-stack-line"></i></span>
                            <span>Ingredients, recipes and purchases</span>
                        </li>
                        <li class="hstack gap-3">
                            <span class="avatar avatar-sm avatar-title rounded-2 bg-info-subtle text-info border-0"><i class="ri-bar-chart-box-line"></i></span>
                            <span>Day close, expenses and reports</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
