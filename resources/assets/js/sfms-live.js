/*
 * SFMS live updates (loaded once in <head>).
 *
 * Shop pages carry <meta name="sfms-live"> when Reverb is configured. This
 * subscribes once per browser tab to the shop's private channel; each
 * "shop.updated" message (which areas changed: orders, day, money, stock, and
 * which order ids) becomes a Livewire event, 'shop-updated', that open screens
 * listen for to reload their own data (App\Livewire\Concerns\RefreshesLive).
 *
 * Bursts are merged: several messages within 300 ms trigger one refresh.
 * <html data-sfms-live="on|off"> tells the page whether updates are live, so
 * screens can show it (.sfms-live-on / .sfms-live-off).
 */
(function () {
    'use strict';

    let echo = null;
    let channel = null;
    let pending = null;
    let timer = null;

    function settings() {
        const meta = document.querySelector('meta[name="sfms-live"]');
        if (!meta) return null;
        try {
            return JSON.parse(meta.getAttribute('content'));
        } catch (e) {
            return null;
        }
    }

    function setState(state) {
        document.documentElement.setAttribute('data-sfms-live', state);
    }

    function deliver() {
        timer = null;
        const change = pending;
        pending = null;
        if (!change || !window.Livewire) return;

        window.Livewire.dispatch('shop-updated', {
            topics: Object.keys(change.topics),
            orders: Object.keys(change.orders).map(Number),
        });
    }

    function receive(message) {
        pending = pending || { topics: {}, orders: {} };
        (message.topics || []).forEach(function (t) { pending.topics[t] = true; });
        (message.orders || []).forEach(function (id) { pending.orders[id] = true; });

        clearTimeout(timer);
        timer = setTimeout(deliver, 300);
    }

    function connect() {
        const config = settings();
        const name = config ? 'shop.' + config.shop : null;

        if (name === channel) return;

        if (echo && channel) {
            echo.leave(channel);
        }
        channel = name;

        if (!config || !window.Pusher || !window.Echo) {
            setState('off');
            return;
        }

        if (!echo) {
            const EchoClass = window.Echo.default || window.Echo;
            echo = new EchoClass({
                broadcaster: 'reverb',
                key: config.key,
                wsHost: config.host,
                wsPort: config.port,
                wssPort: config.port,
                forceTLS: config.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: config.auth,
            });
            window.Echo = echo;
            window.sfmsEcho = echo;

            let lost = false;
            echo.connector.pusher.connection.bind('state_change', function (states) {
                setState(states.current === 'connected' ? 'on' : 'off');

                if (states.previous === 'connected' && states.current !== 'connected') {
                    lost = true;
                }
                // Back after a dropped connection: catch up on anything missed meanwhile.
                if (states.current === 'connected' && lost) {
                    lost = false;
                    receive({ topics: ['orders', 'day', 'money', 'stock'], orders: [] });
                }
            });
        }

        echo.private(name).listen('.shop.updated', receive);
    }

    document.addEventListener('DOMContentLoaded', connect);
    document.addEventListener('livewire:navigated', connect);

    window.sfmsLive = { connect: connect, receive: receive };
})();
