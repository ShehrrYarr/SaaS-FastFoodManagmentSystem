/*
 * SFMS POS helpers (loaded once in <head>).
 *
 * The menu grid filters and the "choose size / add-ons" sheet run entirely in
 * the browser, so tapping items is instant. Choosing an item calls the POS
 * page's addLine() over Livewire, where the price is worked out again on the
 * server; nothing priced here is trusted.
 */
(function () {
    'use strict';

    function formatMoney(symbol, value) {
        const amount = Number(value || 0);
        return symbol + ' ' + amount.toLocaleString('en-US', {
            minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
            maximumFractionDigits: 2,
        });
    }

    window.sfmsMoney = formatMoney;

    /**
     * Alpine data for the POS menu.
     * x-data="sfmsPosMenu(menuById, 'Rs')"
     */
    window.sfmsPosMenu = function (menu, symbol) {
        return {
            menu: menu,
            symbol: symbol,
            q: '',
            cat: 'all',

            item: null,
            variantId: null,
            chosen: {},
            qty: 1,
            note: '',
            error: '',
            sheet: null,

            init() {
                this.sheet = window.bootstrap.Modal.getOrCreateInstance(this.$refs.sheet);
                this.$refs.sheet.addEventListener('hidden.bs.modal', () => { this.item = null; });
            },

            visible(id, categoryId) {
                const q = this.q.trim().toLowerCase();
                if (q !== '') return this.menu[id].search.indexOf(q) !== -1;
                return this.cat === 'all' || this.cat === categoryId;
            },

            get visibleCount() {
                const self = this;
                return Object.keys(this.menu).filter(function (id) {
                    return self.visible(Number(id), self.menu[id].category);
                }).length;
            },

            setCategory(id) {
                this.cat = id;
                this.q = '';
            },

            money(value) {
                return formatMoney(this.symbol, value);
            },

            pick(id, el) {
                const product = this.menu[id];
                if (!product) return;

                if (product.simple) {
                    this.flash(el);
                    this.add(product.variants[0].id, [], 1, '');
                    return;
                }

                this.item = product;
                this.variantId = product.defaultVariant;
                this.chosen = {};
                product.groups.forEach((group) => { this.chosen[group.id] = []; });
                this.qty = 1;
                this.note = '';
                this.error = '';
                this.sheet.show();
            },

            flash(el) {
                if (!el) return;
                el.classList.remove('is-added');
                void el.offsetWidth;
                el.classList.add('is-added');
            },

            isChosen(group, option) {
                return (this.chosen[group.id] || []).indexOf(option.id) !== -1;
            },

            toggle(group, option) {
                const list = this.chosen[group.id] || [];
                this.error = '';

                if (group.max === 1) {
                    this.chosen[group.id] = list.indexOf(option.id) !== -1 && group.min === 0 ? [] : [option.id];
                    return;
                }

                if (list.indexOf(option.id) !== -1) {
                    this.chosen[group.id] = list.filter((id) => id !== option.id);
                    return;
                }

                if (group.max !== null && list.length >= group.max) {
                    this.error = 'Choose up to ' + group.max + ' from ' + group.name + '.';
                    return;
                }

                this.chosen[group.id] = list.concat([option.id]);
            },

            get variant() {
                if (!this.item) return null;
                return this.item.variants.find((v) => v.id === this.variantId) || this.item.variants[0];
            },

            get unitPrice() {
                if (!this.item) return 0;
                let price = Number(this.variant ? this.variant.price : 0);
                this.item.groups.forEach((group) => {
                    group.options.forEach((option) => {
                        if (this.isChosen(group, option)) price += Number(option.price);
                    });
                });
                return price;
            },

            changeQty(delta) {
                this.qty = Math.min(999, Math.max(1, (parseInt(this.qty, 10) || 1) + delta));
            },

            confirm() {
                for (const group of this.item.groups) {
                    const count = (this.chosen[group.id] || []).length;
                    if (count < group.min) {
                        this.error = group.min === 1 ? 'Choose ' + group.name.toLowerCase() + '.' : 'Choose at least ' + group.min + ' from ' + group.name + '.';
                        return;
                    }
                }

                const optionIds = [];
                Object.keys(this.chosen).forEach((key) => { optionIds.push.apply(optionIds, this.chosen[key]); });

                this.add(this.variantId, optionIds, parseInt(this.qty, 10) || 1, this.note);
                this.sheet.hide();
            },

            add(variantId, optionIds, qty, note) {
                this.$wire.$parent.addLine(variantId, optionIds, qty, note || '');
            },
        };
    };

    // On phones the order is a bottom sheet; close it once an order is sent or paid.
    function closeCart() {
        const cart = document.getElementById('posCart');
        if (cart && window.bootstrap && cart.classList.contains('show')) {
            window.bootstrap.Offcanvas.getOrCreateInstance(cart).hide();
        }
    }

    window.addEventListener('pos-close-cart', closeCart);

    // "Paid" confirmation with the change to hand back, after checkout.
    window.addEventListener('pos-paid', function (event) {
        const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        closeCart();
        if (!detail || !window.Swal) return;

        window.Swal.fire({
            icon: 'success',
            title: detail.title,
            html: detail.change
                ? '<div class="fs-15 text-muted">Change to give</div><div class="display-6 fw-bold">' + detail.change + '</div>'
                : '<div class="text-muted">' + (detail.subtitle || '') + '</div>',
            confirmButtonText: 'Next order',
            timer: detail.change ? undefined : 2500,
            timerProgressBar: !detail.change,
        });
    });
})();
