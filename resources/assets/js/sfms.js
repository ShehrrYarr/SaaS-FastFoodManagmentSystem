/*
 * SFMS glue between the Herozi layout and Livewire's wire:navigate.
 *
 * Herozi stores its layout settings (theme, sidebar mode, ...) as attributes
 * on <html>. A wire:navigate page swap replaces those attributes with the
 * server's, so they are re-applied right after every swap. Everything else
 * uses delegated listeners, so it keeps working after swaps without
 * re-binding.
 */
(function () {
    'use strict';

    const html = document.documentElement;
    const SKIP = ['AUTH_LAYOUT'];

    // Herozi's layout.js calls these from its sidebar.js, which SFMS replaces:
    // menu groups toggle via the delegated click handler below and wire:current
    // marks the active link, so they are intentionally no-ops.
    window.dropdownInit = window.dropdownInit || function () {};
    window.findActiveMenu = window.findActiveMenu || function () {};

    function storedOrDefault(attribute) {
        try {
            return sessionStorage.getItem(attribute) || (window.DEFAULT_VALUES || {})[attribute];
        } catch (e) {
            return (window.DEFAULT_VALUES || {})[attribute];
        }
    }

    function resolveTheme(theme) {
        if (theme === 'auto') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return theme || 'light';
    }

    function reapplyLayoutAttributes() {
        if (!window.ATTRIBUTES || html.hasAttribute('data-auth-page')) return;

        Object.keys(window.ATTRIBUTES).forEach(function (key) {
            const attribute = window.ATTRIBUTES[key];
            if (SKIP.indexOf(attribute) !== -1) return;
            const value = storedOrDefault(attribute);
            if (value) html.setAttribute(attribute, value);
        });

        html.setAttribute('data-bs-theme', resolveTheme(storedOrDefault('data-bs-theme')));
        applyResponsiveLayout();
    }

    // Herozi only adapts the sidebar mode on resize; also do it on load and
    // after each navigation so a mode saved on a wider screen never leaks onto a phone.
    function applyResponsiveLayout() {
        if (html.hasAttribute('data-auth-page')) return;

        const current = html.getAttribute('data-main-layout');
        if (!current || current === 'horizontal' || current === 'two-column') return;

        let next = current;
        if (window.innerWidth < 769) {
            next = 'close-sidebar';
        } else if (current === 'close-sidebar') {
            next = window.innerWidth < 1250 ? 'small-icon' : 'vertical';
        }

        if (next !== current) {
            html.setAttribute('data-main-layout', next);
            try { sessionStorage.setItem('data-main-layout', next); } catch (e) { /* private mode */ }
        }
    }

    applyResponsiveLayout();

    function setTheme(theme) {
        // Herozi keeps the sidebar light in dark mode; pair them so it follows the theme.
        const sidebar = resolveTheme(theme) === 'dark' ? 'dark-sidebar' : 'light-sidebar';
        try {
            sessionStorage.setItem('data-bs-theme', theme);
            sessionStorage.setItem('data-sidebar', sidebar);
            localStorage.setItem('sfms-theme', theme);
        } catch (e) { /* private mode */ }
        html.setAttribute('data-bs-theme', resolveTheme(theme));
        html.setAttribute('data-sidebar', sidebar);
    }

    // Carry the chosen theme across tabs, new sessions and the login pages.
    try {
        const saved = sessionStorage.getItem('data-bs-theme') || localStorage.getItem('sfms-theme');
        if (saved) {
            sessionStorage.setItem('data-bs-theme', saved);
            html.setAttribute('data-bs-theme', resolveTheme(saved));
        }
    } catch (e) { /* storage unavailable */ }

    // ---------------------------------------------------------------- toasts

    function toast(type, message) {
        if (!message) return;
        if (window.Swal) {
            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: type || 'success',
                title: message,
                showConfirmButton: false,
                timer: 3200,
                timerProgressBar: true,
            });
        } else {
            console.log('[' + type + '] ' + message);
        }
    }

    window.sfmsToast = toast;

    function showFlashToasts() {
        document.querySelectorAll('[data-flash-toast]').forEach(function (el) {
            try {
                const data = JSON.parse(el.getAttribute('data-flash-toast'));
                toast(data.type, data.message);
            } catch (e) { /* ignore malformed */ }
            el.remove();
        });
    }

    // Livewire: $this->dispatch('toast', type: 'success', message: '...')
    window.addEventListener('toast', function (event) {
        const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        if (detail) toast(detail.type, detail.message);
    });

    // ---------------------------------------------------------------- printing
    //
    // 80mm tickets print through a hidden iframe, so the POS page never reloads.
    // Each device can switch KOT / receipt printing off (no printer at that till).
    // Livewire: $this->dispatch('sfms-print', url: '...', kind: 'kot' | 'receipt')

    const PRINT_KEYS = { kot: 'sfms-print-kot', receipt: 'sfms-print-receipt' };
    const printQueue = [];
    let printing = false;

    function printEnabled(kind) {
        if (!PRINT_KEYS[kind]) return true;
        try { return localStorage.getItem(PRINT_KEYS[kind]) !== '0'; } catch (e) { return true; }
    }

    function setPrintEnabled(kind, on) {
        try { localStorage.setItem(PRINT_KEYS[kind], on ? '1' : '0'); } catch (e) { /* private mode */ }
    }

    function nextPrintJob() {
        if (printing || printQueue.length === 0) return;
        printing = true;

        const url = printQueue.shift();
        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('tabindex', '-1');
        frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:200px;border:0;';

        let done = false;
        const finish = function () {
            if (done) return;
            done = true;
            printing = false;
            setTimeout(function () { frame.remove(); }, 1000);
            nextPrintJob();
        };

        frame.addEventListener('load', function () {
            try {
                frame.contentWindow.addEventListener('afterprint', finish);
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                window.open(url, '_blank');
            }
            // print() blocks in most browsers; this covers the ones where it doesn't.
            setTimeout(finish, 1500);
        });

        frame.src = url;
        // Outside <body>, so a wire:navigate page swap can't remove it mid-print.
        html.appendChild(frame);
    }

    function printUrl(url) {
        if (!url) return;
        printQueue.push(url);
        nextPrintJob();
    }

    window.sfmsPrint = {
        enabled: printEnabled,
        set: setPrintEnabled,
        url: printUrl,
    };

    window.addEventListener('sfms-print', function (event) {
        const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        if (!detail || !detail.url) return;
        if (printEnabled(detail.kind)) printUrl(detail.url);
    });

    // ---------------------------------------------------------------- delegated clicks

    document.addEventListener('click', function (event) {
        // Theme switcher: data-theme-set="light|dark|auto"
        const themeButton = event.target.closest('[data-theme-set]');
        if (themeButton) {
            event.preventDefault();
            setTheme(themeButton.getAttribute('data-theme-set'));
            return;
        }

        // Sidebar groups with children: toggle open/close.
        const groupToggle = event.target.closest('.app-sidebar .slide.has-sub > .side-menu__item');
        if (groupToggle) {
            event.preventDefault();
            groupToggle.parentElement.classList.toggle('open-menu');
            return;
        }

        // Copy-to-clipboard buttons: data-copy="text"
        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            event.preventDefault();
            const text = copyButton.getAttribute('data-copy');
            const done = function () { toast('success', 'Copied to clipboard'); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                const input = document.createElement('textarea');
                input.value = text;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                input.remove();
                done();
            }
        }
    });

    // Close the mobile sidebar drawer as soon as a link inside it is tapped.
    function closeMobileSidebar() {
        const drawer = document.getElementById('smallScreenSidebar');
        if (drawer && window.bootstrap && drawer.classList.contains('show')) {
            window.bootstrap.Offcanvas.getOrCreateInstance(drawer).hide();
        }
    }

    function closeOpenModals() {
        if (!window.bootstrap) return;
        document.querySelectorAll('.modal.show').forEach(function (modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        });
        document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    // ---------------------------------------------------------------- Livewire + Bootstrap
    //
    // Bootstrap 5.3 puts an offcanvas backdrop next to the panel, which can be
    // inside a Livewire component; the server HTML doesn't have it, so a re-render
    // would remove it while the panel stays open. Leave Bootstrap's backdrops alone.
    document.addEventListener('livewire:init', function () {
        window.Livewire.hook('morph.removing', function (payload) {
            const el = payload.el;
            if (el && el.classList && (el.classList.contains('offcanvas-backdrop') || el.classList.contains('modal-backdrop'))) {
                payload.skip();
            }
        });
    });

    // ---------------------------------------------------------------- navigation lifecycle

    document.addEventListener('livewire:navigating', function (event) {
        closeMobileSidebar();
        closeOpenModals();
        if (event.detail && typeof event.detail.onSwap === 'function') {
            event.detail.onSwap(reapplyLayoutAttributes);
        }
    });

    document.addEventListener('livewire:navigated', function () {
        reapplyLayoutAttributes();
        showFlashToasts();
        const preloader = document.getElementById('preloader');
        if (preloader) preloader.classList.add('hidden');
    });

    document.addEventListener('DOMContentLoaded', showFlashToasts);
})();
