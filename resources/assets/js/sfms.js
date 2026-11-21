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
