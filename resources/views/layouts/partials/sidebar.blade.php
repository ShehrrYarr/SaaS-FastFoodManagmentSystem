{{--
    Herozi sidebar (desktop) + offcanvas copy (phones).
    Params: $menu, $homeUrl, $brandMark, $brandText
--}}
<aside class="app-sidebar">
    <div class="app-sidebar-logo px-6 justify-content-center align-items-center">
        <a href="{{ $homeUrl }}" class="sfms-brand" wire:navigate>
            <span class="sfms-brand-mark">{{ $brandMark }}</span>
            <span class="sfms-brand-text">{{ $brandText }}</span>
        </a>
    </div>
    <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" id="sidebarMenu" aria-label="Main navigation">
        @include('layouts.partials.menu', ['menu' => $menu, 'id' => 'all-menu-items'])
    </nav>
</aside>
<div class="horizontal-overlay"></div>

<div class="offcanvas offcanvas-md offcanvas-start small-screen-sidebar" data-bs-scroll="true" tabindex="-1"
     id="smallScreenSidebar" aria-label="Main menu">
    <div class="offcanvas-header hstack border-bottom">
        <a href="{{ $homeUrl }}" class="sfms-brand" wire:navigate>
            <span class="sfms-brand-mark">{{ $brandMark }}</span>
            <span class="sfms-brand-text">{{ $brandText }}</span>
        </a>
        <button type="button" class="btn-close bg-transparent ms-auto" data-bs-dismiss="offcanvas" aria-label="Close menu">
            <i class="ri-close-line"></i>
        </button>
    </div>
    <div class="offcanvas-body p-0">
        <aside class="app-sidebar">
            <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" aria-label="Main navigation">
                @include('layouts.partials.menu', ['menu' => $menu, 'id' => null])
            </nav>
        </aside>
    </div>
</div>
