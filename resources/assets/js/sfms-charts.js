/*
 * SFMS charts (loaded once in <head>).
 *
 * One kind of chart: a single-series column chart (sales by day, orders by
 * hour). ApexCharts is fetched the first time a page draws a chart. Colours
 * come from the theme's CSS variables, so light and dark both work, and the
 * chart redraws when the theme is switched.
 *
 *   <div wire:ignore wire:key="chart-{{ md5(json_encode($data)) }}"
 *        x-data="sfmsBarChart({ labels: [...], values: [...], name: 'Sales', money: true, symbol: 'Rs' })">
 *       <div x-ref="plot"></div>
 *   </div>
 *
 * Give the wrapper a wire:key that changes with the data: Livewire then swaps
 * the element and Alpine draws the new chart (and destroys the old one).
 */
(function () {
    'use strict';

    let loading = null;

    function loadApexCharts() {
        if (window.ApexCharts) {
            return Promise.resolve(window.ApexCharts);
        }
        if (!loading) {
            loading = new Promise(function (resolve, reject) {
                const script = document.createElement('script');
                script.src = (window.sfmsAssets && window.sfmsAssets.apexcharts) || '/assets/libs/apexcharts/apexcharts.min.js';
                script.onload = function () { resolve(window.ApexCharts); };
                script.onerror = function () { loading = null; reject(new Error('Could not load the chart library.')); };
                document.head.appendChild(script);
            });
        }
        return loading;
    }

    function token(name, fallback) {
        const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return value || fallback;
    }

    /** Axis ticks: in full under 10,000, compact above (10K, 1.2M). */
    function compact(value) {
        const n = Number(value || 0);
        const abs = Math.abs(n);
        if (abs >= 1e6) return (n / 1e6).toLocaleString('en-US', { maximumFractionDigits: 1 }) + 'M';
        if (abs >= 1e4) return (n / 1e3).toLocaleString('en-US', { maximumFractionDigits: 1 }) + 'K';
        return n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function money(symbol, value) {
        return window.sfmsMoney ? window.sfmsMoney(symbol, value) : symbol + ' ' + Number(value || 0).toLocaleString('en-US');
    }

    window.sfmsBarChart = function (config) {
        return {
            chart: null,
            observer: null,

            init() {
                const self = this;
                loadApexCharts().then(function () { self.draw(); }).catch(function () {
                    self.$refs.plot.textContent = 'The chart could not be loaded. The figures are in the table below.';
                });

                // Redraw with the other theme's colours when light/dark is switched.
                this.observer = new MutationObserver(function () { if (self.chart) self.draw(); });
                this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
            },

            destroy() {
                if (this.observer) this.observer.disconnect();
                if (this.chart) this.chart.destroy();
                this.chart = null;
            },

            draw() {
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
                }

                const el = this.$refs.plot;
                const count = Math.max(config.values.length, 1);
                const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                const muted = token('--bs-secondary-color', '#6c757d');
                const grid = token('--bs-border-color', '#e9ecef');
                const symbol = config.symbol || '';

                // Columns stay thin (at most 24px) however few there are.
                const plotWidth = el.clientWidth || 600;
                const slot = plotWidth / count;
                const width = Math.max(8, Math.min(60, (24 / slot) * 100));

                // Label every nth column so axis labels never collide; the tooltip names every column.
                const longest = config.labels.reduce(function (max, label) { return Math.max(max, String(label).length); }, 1);
                const fits = Math.max(1, Math.floor(plotWidth / (longest * 7 + 16)));
                const step = Math.ceil(count / fits);
                const labels = config.labels.map(function (label, i) { return i % step === 0 ? label : ''; });

                this.chart = new window.ApexCharts(el, {
                    chart: {
                        type: 'bar',
                        height: config.height || 260,
                        background: 'transparent',
                        fontFamily: token('--bs-body-font-family', 'inherit'),
                        foreColor: muted,
                        toolbar: { show: false },
                        zoom: { enabled: false },
                        animations: { enabled: false },
                        parentHeightOffset: 0,
                    },
                    theme: { mode: dark ? 'dark' : 'light' },
                    series: [{ name: config.name, data: config.values.map(Number) }],
                    colors: [token('--bs-primary', '#3b82f6')],
                    plotOptions: {
                        bar: { columnWidth: width + '%', borderRadius: 4, borderRadiusApplication: 'end' },
                    },
                    dataLabels: { enabled: false },
                    legend: { show: false },
                    grid: {
                        borderColor: grid,
                        strokeDashArray: 0,
                        xaxis: { lines: { show: false } },
                        yaxis: { lines: { show: true } },
                        padding: { left: 4, right: 8 },
                    },
                    xaxis: {
                        categories: labels,
                        axisBorder: { show: true, color: grid },
                        axisTicks: { show: false },
                        tooltip: { enabled: false },
                        labels: { hideOverlappingLabels: false, rotate: 0, trim: false, style: { colors: muted } },
                    },
                    yaxis: {
                        min: 0,
                        forceNiceScale: true,
                        tickAmount: 4,
                        labels: { formatter: compact, style: { colors: [muted] } },
                    },
                    tooltip: {
                        theme: dark ? 'dark' : 'light',
                        x: { formatter: function (value, opts) { return (config.tooltipLabels || config.labels)[opts.dataPointIndex] || value; } },
                        y: {
                            formatter: function (value) {
                                return config.money ? money(symbol, value) : Number(value).toLocaleString('en-US') + (config.unit ? ' ' + config.unit : '');
                            },
                        },
                    },
                    states: { hover: { filter: { type: 'darken', value: 0.85 } }, active: { filter: { type: 'none' } } },
                });

                this.chart.render();
            },
        };
    };
})();
