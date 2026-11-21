{{--
    Herozi top bar.
    Params: $user, $roleLabel, $logoutUrl, $context (optional title), $contextSub (optional)
--}}
<header class="app-header">
    <div class="container-fluid">
        <div class="nav-header">
            <div class="header-left hstack gap-3 min-w-0">
                <button type="button" class="btn btn-light-light icon-btn sidebar-toggle d-none d-md-block" aria-label="Collapse sidebar">
                    <i class="ri-menu-2-fill"></i>
                </button>
                <button type="button" class="btn btn-light-light icon-btn d-md-none" data-bs-toggle="offcanvas"
                        data-bs-target="#smallScreenSidebar" aria-controls="smallScreenSidebar" aria-label="Open menu">
                    <i class="ri-menu-2-fill"></i>
                </button>

                @if (! empty($context))
                    <div class="d-flex flex-column min-w-0 lh-sm">
                        <span class="fw-semibold text-truncate">{{ $context }}</span>
                        @if (! empty($contextSub))
                            <span class="fs-12 text-muted text-truncate">{{ $contextSub }}</span>
                        @endif
                    </div>
                @endif
            </div>

            <div class="header-right hstack gap-2 gap-sm-3">
                <div class="dropdown features-dropdown">
                    <button type="button" class="btn icon-btn btn-text-primary rounded-circle" data-bs-toggle="dropdown"
                            aria-expanded="false" aria-label="Light or dark mode">
                        <i class="ri-contrast-2-line fs-20"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <button type="button" class="dropdown-item hstack gap-2" data-theme-set="light"><i class="ri-sun-line"></i>Light</button>
                        <button type="button" class="dropdown-item hstack gap-2" data-theme-set="dark"><i class="ri-moon-clear-line"></i>Dark</button>
                        <button type="button" class="dropdown-item hstack gap-2" data-theme-set="auto"><i class="ri-computer-line"></i>System</button>
                    </div>
                </div>

                <div class="dropdown profile-dropdown features-dropdown">
                    <button type="button" class="btn profile-btn shadow-none px-0 hstack gap-0 gap-sm-3"
                            data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                        <span class="avatar-item avatar avatar-title bg-primary-subtle text-primary fw-semibold fs-12 border-0">
                            {{ $user?->initials() }}
                        </span>
                        <span class="d-none d-xl-block text-start">
                            <span class="h6 d-block fw-semibold mb-0">{{ $user?->name }}</span>
                            <span class="d-block fs-12 text-muted">{{ $roleLabel }}</span>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="px-4 py-2">
                            <div class="fw-semibold">{{ $user?->name }}</div>
                            <div class="fs-12 text-muted text-truncate">{{ $user?->loginName() }}</div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ $logoutUrl }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger hstack gap-2">
                                <i class="ri-logout-box-r-line"></i>Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
