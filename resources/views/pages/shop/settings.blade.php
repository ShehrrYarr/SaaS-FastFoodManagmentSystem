<?php

use App\Models\Shop;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::shop')] #[Title('Settings')] class extends Component
{
    use WithFileUploads;

    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $orderPrefix = '';
    public string $receiptHeader = '';
    public string $receiptFooter = '';
    public string $serviceChargeType = 'percent';
    public string $serviceChargeValue = '0';
    public string $defaultDeliveryCharge = '0';
    public bool $inventoryTrackingEnabled = false;
    public bool $allowNegativeStock = true;
    public string $currency = 'PKR';
    public string $currencySymbol = 'Rs';
    public string $timezone = 'Asia/Karachi';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    public function mount(): void
    {
        $shop = $this->shop;

        $this->name = $shop->name;
        $this->phone = (string) $shop->phone;
        $this->email = (string) $shop->email;
        $this->address = (string) $shop->address;
        $this->orderPrefix = $shop->order_prefix;
        $this->receiptHeader = (string) $shop->receipt_header;
        $this->receiptFooter = (string) $shop->receipt_footer;
        $this->serviceChargeType = $shop->service_charge_type;
        $this->serviceChargeValue = rtrim(rtrim((string) $shop->service_charge_value, '0'), '.') ?: '0';
        $this->defaultDeliveryCharge = rtrim(rtrim((string) $shop->default_delivery_charge, '0'), '.') ?: '0';
        $this->inventoryTrackingEnabled = $shop->inventory_tracking_enabled;
        $this->allowNegativeStock = $shop->allow_negative_stock;
        $this->currency = $shop->currency;
        $this->currencySymbol = $shop->currency_symbol;
        $this->timezone = $shop->timezone;
    }

    #[Computed]
    public function shop(): Shop
    {
        return app(TenantContext::class)->shop();
    }

    public function save(): void
    {
        $this->orderPrefix = Str::upper(preg_replace('/[^a-z0-9]/i', '', $this->orderPrefix));

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'orderPrefix' => ['required', 'regex:'.Shop::ORDER_PREFIX_RULE],
            'receiptHeader' => ['nullable', 'string', 'max:255'],
            'receiptFooter' => ['nullable', 'string', 'max:255'],
            'serviceChargeType' => ['required', 'in:percent,fixed'],
            'serviceChargeValue' => ['required', 'numeric', 'min:0', $this->serviceChargeType === 'percent' ? 'max:100' : 'max:100000'],
            'defaultDeliveryCharge' => ['required', 'numeric', 'min:0', 'max:100000'],
            'inventoryTrackingEnabled' => ['boolean'],
            'allowNegativeStock' => ['boolean'],
            'currency' => ['required', 'string', 'max:10'],
            'currencySymbol' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'timezone:all'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'orderPrefix.regex' => 'Use 2-10 capital letters or numbers, e.g. BLG.',
        ], [
            'orderPrefix' => 'order number prefix',
            'serviceChargeValue' => 'service charge',
            'defaultDeliveryCharge' => 'delivery charge',
            'currencySymbol' => 'currency symbol',
        ]);

        $shop = $this->shop;
        $inventoryChanged = $shop->inventory_tracking_enabled !== $this->inventoryTrackingEnabled;

        $data = [
            'name' => trim($this->name),
            'phone' => $this->phone ?: null,
            'email' => $this->email ?: null,
            'address' => $this->address ?: null,
            'order_prefix' => $this->orderPrefix,
            'receipt_header' => $this->receiptHeader ?: null,
            'receipt_footer' => $this->receiptFooter ?: null,
            'service_charge_type' => $this->serviceChargeType,
            'service_charge_value' => $this->serviceChargeValue,
            'default_delivery_charge' => $this->defaultDeliveryCharge,
            'inventory_tracking_enabled' => $this->inventoryTrackingEnabled,
            'allow_negative_stock' => $this->allowNegativeStock,
            'currency' => Str::upper($this->currency),
            'currency_symbol' => $this->currencySymbol,
            'timezone' => $this->timezone,
        ];

        if ($this->logo) {
            $data['logo_path'] = $this->logo->store("shops/{$shop->id}/branding", 'public');

            if ($shop->logo_path) {
                Storage::disk('public')->delete($shop->logo_path);
            }
        }

        $shop->update($data);
        $this->reset('logo');

        if ($inventoryChanged) {
            // Full reload so the sidebar shows or hides the Inventory section.
            session()->flash('toast', ['type' => 'success', 'message' => 'Settings saved.']);
            $this->redirectRoute('shop.settings');

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Settings saved.');
    }

    public function removeLogo(): void
    {
        $shop = $this->shop;

        if ($shop->logo_path) {
            Storage::disk('public')->delete($shop->logo_path);
            $shop->update(['logo_path' => null]);
        }

        $this->reset('logo');
        $this->dispatch('toast', type: 'success', message: 'Logo removed.');
    }
};
?>

<div>
    <x-page-header title="Settings" subtitle="Your shop's details, receipts, charges and features." />

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                {{-- Profile --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Shop profile</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <x-input class="col-md-6" model="name" label="Shop name" required />
                            <x-input class="col-md-6" model="phone" label="Phone" type="tel" />
                            <x-input class="col-md-6" model="email" label="Email" type="email" />
                            <x-input class="col-md-6" model="orderPrefix" label="Order number prefix" maxlength="10"
                                     hint="New orders: {{ \App\Models\ShopSequence::format($orderPrefix ?: 'XXX', 1) }}" modifier="blur" />
                            <x-input class="col-12" model="address" label="Address" />
                        </div>
                    </div>
                </div>

                {{-- Receipt --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Receipt</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <x-input class="col-12" model="receiptHeader" label="Header line" placeholder="e.g. NTN 1234567-8 · Open 12pm-2am"
                                     hint="Printed under the shop name." />
                            <x-input class="col-12" model="receiptFooter" label="Footer line" placeholder="e.g. Thank you! Visit again."
                                     hint="Printed at the bottom of every receipt." />
                        </div>
                    </div>
                </div>

                {{-- Charges --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Charges</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <x-select class="col-md-4" model="serviceChargeType" label="Service charge type" modifier="live"
                                      :options="['percent' => 'Percentage (%)', 'fixed' => 'Fixed amount']" />
                            <x-input class="col-md-4" model="serviceChargeValue" label="Service charge" inputmode="decimal"
                                     :prepend="$serviceChargeType === 'fixed' ? $currencySymbol : null"
                                     :append="$serviceChargeType === 'percent' ? '%' : null"
                                     hint="Added to dine-in bills. 0 = none." />
                            <x-input class="col-md-4" model="defaultDeliveryCharge" label="Delivery charge" inputmode="decimal"
                                     :prepend="$currencySymbol" hint="Default for delivery orders." />
                        </div>
                    </div>
                </div>

                {{-- Inventory --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Inventory</h5></div>
                    <div class="card-body vstack gap-3">
                        <x-switch model="inventoryTrackingEnabled" modifier="live" label="Track ingredient inventory"
                                  description="Off: products sell without recipes or stock. On: manage ingredients, purchases and recipes; stock is deducted when items go to the kitchen." />
                        @if ($inventoryTrackingEnabled)
                            <x-switch model="allowNegativeStock" label="Allow selling when stock runs out"
                                      description="On: the POS warns and lets stock go below zero (good when counts are approximate). Off: items with missing ingredients cannot be sent to the kitchen." />
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                {{-- Logo --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Logo</h5></div>
                    <div class="card-body text-center">
                        <div class="sfms-auth-logo mx-auto mb-3" style="width: 6rem; height: 6rem; font-size: 1.5rem;">
                            @if ($logo && method_exists($logo, 'temporaryUrl'))
                                <img src="{{ $logo->temporaryUrl() }}" alt="New logo preview">
                            @elseif ($this->shop->logo_path)
                                <img src="{{ Storage::disk('public')->url($this->shop->logo_path) }}" alt="Shop logo">
                            @else
                                {{ $this->shop->initials() }}
                            @endif
                        </div>
                        <label class="btn btn-light btn-touch mb-2">
                            <i class="ri-upload-2-line"></i> Choose image
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="d-none">
                        </label>
                        <div wire:loading wire:target="logo" class="text-muted fs-13">Uploading&hellip;</div>
                        @error('logo') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        <div class="form-text">Square PNG, JPG or WebP, up to 2 MB. Shown on sign-in and receipts.</div>
                        @if ($this->shop->logo_path && ! $logo)
                            <button type="button" class="btn btn-link text-danger btn-sm mt-2" wire:click="removeLogo"
                                    wire:confirm="Remove the logo?">Remove logo</button>
                        @endif
                    </div>
                </div>

                {{-- Regional --}}
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Regional</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <x-input class="col-6" model="currency" label="Currency" maxlength="10" />
                            <x-input class="col-6" model="currencySymbol" label="Symbol" maxlength="10" />
                            <x-select class="col-12" model="timezone" label="Timezone"
                                      :options="array_combine($tz = ['Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kolkata', 'Europe/London', 'UTC'], $tz)" />
                        </div>
                    </div>
                </div>

                {{-- Web address --}}
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="text-muted fs-13 mb-1">Staff sign-in link</div>
                        <div class="hstack gap-2">
                            <span class="sfms-mono text-truncate">{{ $this->shop->loginUrl() }}</span>
                            <button type="button" class="btn btn-sm btn-light flex-shrink-0" data-copy="{{ $this->shop->loginUrl() }}" aria-label="Copy link">
                                <i class="ri-file-copy-line"></i>
                            </button>
                        </div>
                        <div class="form-text">The address can only be changed by support.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card sfms-sticky-actions mb-0">
            <div class="card-body hstack justify-content-end gap-2 py-3">
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save,logo">
                    <span wire:loading.remove wire:target="save"><i class="ri-save-3-line"></i> Save settings</span>
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" role="status"></span>
                </button>
            </div>
        </div>
    </form>
</div>
