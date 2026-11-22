<?php

use App\Models\Shop;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The public landing page: what SFMS is, one-tap demo roles, a tour of real
 * screens, pricing and contact on WhatsApp, and "sign in to your shop".
 */
new #[Layout('layouts::landing')] class extends Component
{
    public string $code = '';

    /** "Already using SFMS?": go to a shop's sign-in page by its code. */
    public function go(): void
    {
        $this->validate(['code' => ['required', 'string', 'max:40']], [], ['code' => 'shop code']);

        $shop = Shop::query()->where('prefix', Str::lower(trim($this->code, " /\t\n")))->first();

        if (! $shop) {
            $this->addError('code', 'We could not find a shop with that code.');

            return;
        }

        $this->redirect($shop->loginUrl());
    }

    public function whatsapp(string $message): string
    {
        return 'https://wa.me/'.config('sfms.whatsapp').'?text='.rawurlencode($message);
    }

    public function demoEnabled(): bool
    {
        return filled(config('sfms.demo_shop'));
    }

    public function phone(): string
    {
        $digits = (string) config('sfms.whatsapp');

        // 923120883979 -> +92 312 0883979
        return str_starts_with($digits, '92') && strlen($digits) === 12
            ? '+92 '.substr($digits, 2, 3).' '.substr($digits, 5)
            : '+'.$digits;
    }
};
?>

@php
    $pricingLink = $this->whatsapp('Hi! I\'m interested in SFMS for my shop. Could you share the pricing?');
    $contactLink = $this->whatsapp('Hi! I\'d like to get SFMS set up for my fast-food shop.');
    $screens = [
        'pos' => ['POS', 'ri-shopping-basket-2-line', 'Tap items, pick sizes and add-ons, send to the kitchen. The bill, service charge and token are worked out for you.'],
        'tabs' => ['Open tabs', 'ri-restaurant-2-line', 'Every table, takeaway and delivery at a glance, with minutes open and amount due, updated live on every device.'],
        'reports' => ['Reports', 'ri-bar-chart-box-line', 'Sales by day and hour, best sellers, payments, staff and a profit estimate after ingredients and expenses.'],
        'day' => ['Day close', 'ri-safe-2-line', 'Opening count, cash sales and every drawer movement add up to the cash you should have. Count it and close.'],
        'dashboard' => ['Dashboard', 'ri-dashboard-3-line', 'Today at a glance: sales, open tabs, low stock, the last seven days and what is in each account.'],
    ];
    $features = [
        ['ri-shopping-basket-2-line', 'POS for every order type', 'Dine-in by table and waiter, takeaway with daily tokens, delivery with rider dispatch. Sizes, add-ons and combo deals built in.'],
        ['ri-restaurant-2-line', 'Open tabs board', 'All running tables and orders on one screen. Add items, move on, pay at the end, from any till.'],
        ['ri-printer-line', 'Kitchen tickets & voids', '80 mm kitchen tickets print as you send. Voids need a reason and print a VOID ticket, so nothing disappears quietly.'],
        ['ri-bank-card-line', 'Split payments', 'Cash, bank and wallets like JazzCash and Easypaisa on one bill, with the change worked out and references saved.'],
        ['ri-scales-3-line', 'Stock by recipe', 'Every sale takes the exact grams and pieces its recipe uses. Purchases update average cost; low stock warns you.'],
        ['ri-safe-2-line', 'A day close that balances', 'Every rupee in or out of the drawer is on its ledger, so the night count is checked line by line.'],
        ['ri-wallet-3-line', 'Expenses & accounts', 'Rent, bills, transfers and owner drawings, each account with its own running ledger.'],
        ['ri-line-chart-line', 'Reports & profit', 'Sales by day, item, category and hour, cashier and waiter totals, and profit after ingredients and expenses.'],
        ['ri-shield-user-line', 'Staff roles', 'Owner, manager and cashier out of the box, or your own roles with exactly the permissions you choose.'],
    ];
    $roles = [
        'owner' => ['Owner', 'ri-vip-crown-2-line', 'Everything: reports, money, menu, stock, staff and settings.', ['Profit and sales reports', 'Accounts, expenses and transfers', 'Menu, recipes and staff roles']],
        'manager' => ['Manager', 'ri-briefcase-4-line', 'Runs the shop day to day without touching staff or settings.', ['Open and close the day', 'Purchases and stock counts', 'Discounts, voids and reports']],
        'cashier' => ['Cashier', 'ri-user-smile-line', 'The counter: take orders, run tabs, take payment.', ['POS and open tabs', 'Kitchen tickets and voids', 'Split payments and receipts']],
    ];
@endphp

<div>
    {{-- ============================================================ navigation --}}
    <header class="lp-nav" x-data="{ open: false, scrolled: false }" @scroll.window="scrolled = window.scrollY > 12" :class="{ 'is-scrolled': scrolled || open }">
        <div class="container lp-nav-inner">
            <a href="#top" class="lp-brand" aria-label="SFMS home">
                <span class="lp-brand-mark" aria-hidden="true"><i class="ri-restaurant-2-fill"></i></span>
                <span class="lp-brand-name">SFMS</span>
            </a>

            <nav class="lp-links" :class="{ 'is-open': open }" aria-label="Main">
                <a href="#features" @click="open = false">Features</a>
                <a href="#screens" @click="open = false">Screens</a>
                <a href="#demo" @click="open = false">Demo</a>
                <a href="#pricing" @click="open = false">Pricing</a>
                <a href="#signin" @click="open = false">Sign in</a>
                <a href="{{ $this->demoEnabled() ? '#demo' : $contactLink }}" class="btn lp-btn lp-btn-primary lp-nav-cta" @click="open = false">Try the demo</a>
            </nav>

            <button type="button" class="lp-burger" @click="open = !open" :aria-expanded="open" aria-label="Menu">
                <i :class="open ? 'ri-close-line' : 'ri-menu-line'" class="ri-menu-line"></i>
            </button>
        </div>
    </header>

    <main id="top">
        {{-- ============================================================ hero --}}
        <section class="lp-hero">
            <div class="lp-hero-glow" aria-hidden="true"></div>
            <div class="container position-relative">
                <div class="row align-items-center g-5">
                    <div class="col-lg-6">
                        <span class="lp-eyebrow"><i class="ri-fire-line"></i>POS &amp; back office for fast-food shops</span>
                        <h1 class="lp-hero-title">Take every order. Close every day. <span class="lp-gradient-text">Know every rupee.</span></h1>
                        <p class="lp-hero-lead">
                            SFMS runs your counter, kitchen and books in one place: dine-in, takeaway and delivery, kitchen tickets,
                            stock by recipe, expenses and a day-end cash count that matches the books to the rupee.
                            On any phone, tablet or PC.
                        </p>
                        <div class="lp-hero-actions">
                            <a href="{{ $this->demoEnabled() ? '#demo' : $contactLink }}" class="btn lp-btn lp-btn-primary lp-btn-lg">
                                <i class="ri-play-circle-line"></i>Try the live demo
                            </a>
                            <a href="{{ $pricingLink }}" target="_blank" rel="noopener" class="btn lp-btn lp-btn-glass lp-btn-lg">
                                <i class="ri-whatsapp-line"></i>Ask for the pricing
                            </a>
                        </div>
                        <ul class="lp-hero-points">
                            <li><i class="ri-checkbox-circle-fill"></i>No sign-up for the demo</li>
                            <li><i class="ri-checkbox-circle-fill"></i>Runs in the browser</li>
                            <li><i class="ri-checkbox-circle-fill"></i>Prices in rupees</li>
                        </ul>
                    </div>

                    <div class="col-lg-6">
                        <div class="lp-hero-visual">
                            <figure class="lp-browser">
                                <div class="lp-browser-bar" aria-hidden="true"><span></span><span></span><span></span><em>/blg/pos</em></div>
                                <img src="{{ asset('assets/images/landing/pos.webp') }}" alt="The SFMS POS taking a dine-in order" width="1440" height="900" fetchpriority="high">
                            </figure>
                            <figure class="lp-phone lp-hero-phone">
                                <img src="{{ asset('assets/images/landing/mobile-tabs.webp') }}" alt="The open tabs board on a phone" width="390" height="844" loading="lazy">
                            </figure>
                            <div class="lp-float-card lp-float-a" aria-hidden="true">
                                <i class="ri-checkbox-circle-fill"></i>
                                <div><strong>Cash matches</strong><span>Day closed · Rs 0 short</span></div>
                            </div>
                            <div class="lp-float-card lp-float-b" aria-hidden="true">
                                <i class="ri-printer-line"></i>
                                <div><strong>KOT #14 sent</strong><span>T-03 · 3 items</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================================ facts strip --}}
        <section class="lp-strip">
            <div class="container">
                <ul class="lp-strip-list">
                    <li><i class="ri-store-3-line"></i>Dine-in · takeaway · delivery</li>
                    <li><i class="ri-printer-line"></i>80 mm tickets &amp; receipts</li>
                    <li><i class="ri-flashlight-line"></i>Live on every till</li>
                    <li><i class="ri-smartphone-line"></i>Phone, tablet or PC</li>
                    <li><i class="ri-links-line"></i>Your shop, your own link</li>
                </ul>
            </div>
        </section>

        {{-- ============================================================ demo --}}
        <section class="lp-section lp-demo" id="demo">
            <div class="container">
                <div class="lp-heading" data-reveal>
                    <span class="lp-kicker">Live demo</span>
                    <h2>Pick a seat and you're in</h2>
                    <p>One tap opens <strong>Burger Lab</strong>, a demo shop with two weeks of real-looking orders, stock and money. No sign-up, no password.</p>
                </div>

                <div class="row g-4 justify-content-center">
                    @foreach ($roles as $key => [$label, $icon, $summary, $points])
                        <div class="col-md-6 col-lg-4" data-reveal>
                            <div class="lp-role {{ $key === 'owner' ? 'is-featured' : '' }}">
                                @if ($key === 'owner')<span class="lp-role-badge">Full access</span>@endif
                                <span class="lp-role-icon"><i class="{{ $icon }}"></i></span>
                                <h3>{{ $label }}</h3>
                                <p>{{ $summary }}</p>
                                <ul>
                                    @foreach ($points as $point)
                                        <li><i class="ri-check-line"></i>{{ $point }}</li>
                                    @endforeach
                                </ul>
                                @if ($this->demoEnabled())
                                    <a href="{{ route('demo', ['role' => $key]) }}" class="btn lp-btn {{ $key === 'owner' ? 'lp-btn-primary' : 'lp-btn-outline' }} w-100">
                                        Enter as {{ $label }}<i class="ri-arrow-right-line"></i>
                                    </a>
                                @else
                                    <a href="{{ $contactLink }}" target="_blank" rel="noopener" class="btn lp-btn lp-btn-outline w-100">Ask for a demo<i class="ri-arrow-right-line"></i></a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="lp-demo-note" data-reveal><i class="ri-refresh-line"></i>The demo is shared and starts fresh every night, so try anything.</p>
            </div>
        </section>

        {{-- ============================================================ features --}}
        <section class="lp-section lp-features" id="features">
            <div class="container">
                <div class="lp-heading" data-reveal>
                    <span class="lp-kicker">Everything at the counter and behind it</span>
                    <h2>One system from first order to day close</h2>
                    <p>Built around how a fast-food shop actually runs: busy counters, a kitchen that needs tickets, riders on the road and a drawer that has to add up.</p>
                </div>
                <div class="row g-4">
                    @foreach ($features as [$icon, $title, $text])
                        <div class="col-md-6 col-lg-4" data-reveal>
                            <div class="lp-feature">
                                <span class="lp-feature-icon"><i class="{{ $icon }}"></i></span>
                                <h3>{{ $title }}</h3>
                                <p>{{ $text }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================================================ screens tour --}}
        <section class="lp-section lp-screens" id="screens" x-data="{ tab: 'pos' }">
            <div class="container">
                <div class="lp-heading" data-reveal>
                    <span class="lp-kicker">Real screens</span>
                    <h2>See it before you try it</h2>
                    <p>These are the actual screens from the demo shop, not mock-ups.</p>
                </div>

                <div class="lp-tabs" role="tablist" data-reveal>
                    @foreach ($screens as $key => [$label, $icon])
                        <button type="button" role="tab" class="lp-tab" :class="{ 'is-active': tab === '{{ $key }}' }"
                                :aria-selected="tab === '{{ $key }}'" @click="tab = '{{ $key }}'">
                            <i class="{{ $icon }}"></i>{{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="lp-tour" data-reveal>
                    @foreach ($screens as $key => [$label, $icon, $caption])
                        <div class="lp-tour-slide" x-show="tab === '{{ $key }}'" x-transition.opacity.duration.300ms @if ($key !== 'pos') x-cloak style="display:none" @endif>
                            <figure class="lp-browser lp-browser-lg">
                                <div class="lp-browser-bar" aria-hidden="true"><span></span><span></span><span></span><em>/blg/{{ $key }}</em></div>
                                <img src="{{ asset('assets/images/landing/'.$key.'.webp') }}" alt="{{ $label }}: {{ $caption }}" width="1440" height="900" loading="lazy">
                            </figure>
                            <p class="lp-tour-caption"><i class="{{ $icon }}"></i>{{ $caption }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================================================ how a day runs --}}
        <section class="lp-section lp-day">
            <div class="container">
                <div class="lp-heading" data-reveal>
                    <span class="lp-kicker">How a day runs</span>
                    <h2>From the morning count to the Z report</h2>
                </div>
                <ol class="lp-steps">
                    <li data-reveal><span>1</span><h3>Open the day</h3><p>Count the drawer. Any difference is recorded, so the day starts from real cash.</p></li>
                    <li data-reveal><span>2</span><h3>Take orders</h3><p>Dine-in, takeaway or delivery. Each order gets its number and a daily token.</p></li>
                    <li data-reveal><span>3</span><h3>Send to the kitchen</h3><p>Tickets print, stock is taken by recipe, and every till sees the tab.</p></li>
                    <li data-reveal><span>4</span><h3>Take payment</h3><p>Split across cash, bank and wallets. Change is worked out, the receipt prints.</p></li>
                    <li data-reveal><span>5</span><h3>Close and count</h3><p>Expected cash comes from the ledger. Count, close, and the Z report prints.</p></li>
                </ol>
            </div>
        </section>

        {{-- ============================================================ mobile --}}
        <section class="lp-section lp-mobile">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-6 order-lg-2" data-reveal>
                        <span class="lp-kicker">Made for the phone in your pocket</span>
                        <h2 class="lp-h2">Run the floor from a phone</h2>
                        <p class="lp-lead">Waiters take orders at the table, managers check today's sales from home, and owners see every account. Big buttons, no sideways scrolling, and the same live data on every screen.</p>
                        <ul class="lp-checks">
                            <li><i class="ri-checkbox-circle-fill"></i>Cart becomes a bottom bar; one tap to send</li>
                            <li><i class="ri-checkbox-circle-fill"></i>Tabs, orders and reports fit any screen</li>
                            <li><i class="ri-checkbox-circle-fill"></i>Changes on one till appear on all of them instantly</li>
                        </ul>
                    </div>
                    <div class="col-lg-6 order-lg-1" data-reveal>
                        <div class="lp-phones">
                            <figure class="lp-phone"><img src="{{ asset('assets/images/landing/mobile-pos.webp') }}" alt="The POS menu on a phone" width="390" height="844" loading="lazy"></figure>
                            <figure class="lp-phone lp-phone-back"><img src="{{ asset('assets/images/landing/mobile-reports.webp') }}" alt="Reports on a phone" width="390" height="844" loading="lazy"></figure>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================================ pricing --}}
        <section class="lp-section lp-pricing" id="pricing">
            <div class="container">
                <div class="lp-pricing-card" data-reveal>
                    <div class="row align-items-center g-4">
                        <div class="col-lg-7">
                            <span class="lp-kicker lp-kicker-light">Pricing</span>
                            <h2>Simple monthly pricing for your shop</h2>
                            <p>Pricing depends on how many shops and tills you run. Send us a message on WhatsApp and we'll share the plans that fit you.</p>
                            <ul class="lp-pricing-list">
                                <li><i class="ri-check-line"></i>POS, tabs and kitchen tickets</li>
                                <li><i class="ri-check-line"></i>Stock by recipe and purchases</li>
                                <li><i class="ri-check-line"></i>Expenses, ledgers and day close</li>
                                <li><i class="ri-check-line"></i>Reports and live updates</li>
                            </ul>
                        </div>
                        <div class="col-lg-5 text-lg-center">
                            <a href="{{ $pricingLink }}" target="_blank" rel="noopener" class="btn lp-btn lp-btn-wa lp-btn-lg">
                                <i class="ri-whatsapp-fill"></i>Ask for the pricing
                            </a>
                            <p class="lp-pricing-note">Opens WhatsApp · {{ $this->phone() }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================================ get started + sign in --}}
        <section class="lp-section lp-start" id="signin">
            <div class="container">
                <div class="row g-4">
                    <div class="col-lg-7" data-reveal>
                        <div class="lp-panel h-100">
                            <span class="lp-kicker">Get started</span>
                            <h2 class="lp-h2">Get your shop live</h2>
                            <ol class="lp-start-steps">
                                <li><strong>Message us on WhatsApp</strong> with your shop name and how many tills you use.</li>
                                <li><strong>We set up your workspace</strong> with its own link, like <span class="lp-mono">{{ preg_replace('#^https?://#', '', url('your-shop')) }}</span>.</li>
                                <li><strong>Add your menu and staff</strong>, open the day and start selling.</li>
                            </ol>
                            <a href="{{ $contactLink }}" target="_blank" rel="noopener" class="btn lp-btn lp-btn-wa">
                                <i class="ri-whatsapp-fill"></i>Chat on WhatsApp · {{ $this->phone() }}
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-5" data-reveal>
                        <div class="lp-panel h-100">
                            <span class="lp-kicker">Already using SFMS?</span>
                            <h2 class="lp-h2">Sign in to your shop</h2>
                            <p class="text-muted mb-3">Enter your shop code to go to its sign-in page.</p>
                            <form wire:submit="go" novalidate>
                                <label class="form-label" for="lp-code">Shop code</label>
                                <div class="input-group has-validation mb-3">
                                    <span class="input-group-text lp-mono">/</span>
                                    <input type="text" id="lp-code" wire:model="code" placeholder="e.g. blg" autocapitalize="none" autocomplete="off" spellcheck="false"
                                           class="form-control lp-input lp-mono @error('code') is-invalid @enderror">
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <button type="submit" class="btn lp-btn lp-btn-dark w-100" wire:loading.attr="disabled" wire:target="go">
                                    Continue<i class="ri-arrow-right-line"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    {{-- ============================================================ footer --}}
    <footer class="lp-footer">
        <div class="container lp-footer-inner">
            <a href="#top" class="lp-brand">
                <span class="lp-brand-mark" aria-hidden="true"><i class="ri-restaurant-2-fill"></i></span>
                <span class="lp-brand-name">SFMS</span>
            </a>
            <nav class="lp-footer-links" aria-label="Footer">
                <a href="#demo">Demo</a>
                <a href="#features">Features</a>
                <a href="#pricing">Pricing</a>
                <a href="#signin">Shop sign in</a>
                <a href="{{ route('admin.login') }}">Super Admin</a>
            </nav>
            <p class="lp-footer-copy">&copy; {{ now()->year }} SFMS · Fast-food management &amp; POS</p>
        </div>
    </footer>

    {{-- ============================================================ WhatsApp --}}
    <a href="{{ $contactLink }}" target="_blank" rel="noopener" class="lp-wa-float" aria-label="Chat with us on WhatsApp ({{ $this->phone() }})">
        <span class="lp-wa-ring" aria-hidden="true"></span>
        <i class="ri-whatsapp-fill" aria-hidden="true"></i>
    </a>
</div>
