<?php

use App\Models\BusinessDay;
use App\Services\BusinessDays;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::shop')] class extends Component
{
    #[Locked]
    public int $dayId;

    public function mount(BusinessDay $businessDay): void
    {
        $this->dayId = $businessDay->id;
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function day(): BusinessDay
    {
        return BusinessDay::query()->with(['openedBy', 'closedBy'])->findOrFail($this->dayId);
    }

    #[Computed]
    public function summary(): array
    {
        return $this->day->isOpen() ? app(BusinessDays::class)->summary($this->day) : $this->day->summary;
    }

    public function render()
    {
        return $this->view()->title('Day report · '.$this->day->business_date->format('d M Y'));
    }
};
?>

<div>
    @php($tz = $this->shop->timezone)
    <x-page-header :title="'Day report · '.$this->day->label()"
                   :breadcrumbs="['Business day' => route('shop.day'), $this->day->business_date->format('d M Y') => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-primary btn-touch" onclick="sfmsPrint.url('{{ route('shop.print.day', ['businessDay' => $this->day->id]) }}')">
                <i class="ri-printer-line"></i>Print
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-3">
        <div class="card-body hstack flex-wrap gap-3 gap-md-5 fs-13">
            <div><span class="text-muted">Opened</span><br><span class="fw-medium">{{ $this->day->opened_at->timezone($tz)->format('d M, h:i A') }}</span> &middot; {{ $this->day->openedBy?->name }}</div>
            @if ($this->day->closed_at)
                <div><span class="text-muted">Closed</span><br><span class="fw-medium">{{ $this->day->closed_at->timezone($tz)->format('d M, h:i A') }}</span> &middot; {{ $this->day->closedBy?->name }}</div>
            @else
                <div><span class="badge bg-success-subtle text-success">Open now</span></div>
            @endif
            @if ($this->day->opening_note)<div><span class="text-muted">Opening note</span><br>{{ $this->day->opening_note }}</div>@endif
            @if ($this->day->closing_note)<div><span class="text-muted">Closing note</span><br>{{ $this->day->closing_note }}</div>@endif
        </div>
    </div>

    @include('partials.day-summary', ['summary' => $this->summary, 'shop' => $this->shop])
</div>
