/**
 * ApexCharts bridge for `x-ui.chart`.
 *
 * Colours come from the CSS design tokens at render time, so charts follow the
 * light/dark theme and never carry raw hex of their own. ApexCharts is imported
 * lazily: pages without a chart never download it.
 */

const toneTokens = {
    critical: '--color-primary',
    good: '--color-status-good',
    warn: '--color-status-warn',
    funding: '--color-funding',
    neutral: '--color-secondary-light',
    muted: '--color-secondary-soft',
    strong: '--color-secondary-dark',
};

const categoricalTones = ['funding', 'critical', 'good', 'warn', 'strong', 'neutral'];

function isDark() {
    return document.documentElement.classList.contains('dark');
}

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function toneColor(tone) {
    if (tone === 'strong' && isDark()) {
        return token('--color-secondary-soft');
    }

    if (tone === 'muted' && isDark()) {
        return token('--color-secondary-medium');
    }

    return token(toneTokens[tone] ?? toneTokens.neutral);
}

function numberFormatter(currency) {
    // Fixed to 'en-US' rather than the page's own language: the rest of the app
    // (dates, x-ui.money) always renders Western digits and Latin unit letters,
    // even in Arabic, and Intl would otherwise switch to Arabic-Indic digits.
    const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });
    const full = new Intl.NumberFormat('en-US', { maximumFractionDigits: currency ? 0 : 1 });

    return {
        axis: (value) => (currency ? `${compact.format(value)}` : full.format(value)),
        tooltip: (value) => (currency ? `${full.format(value)} ${currency}` : full.format(value)),
    };
}

function buildOptions(config) {
    const dark = isDark();
    const text = dark ? token('--color-secondary-soft') : token('--color-secondary-medium');
    const grid = dark ? token('--color-secondary-medium') : token('--color-secondary-soft');
    const format = numberFormatter(config.currency);
    const tones = config.tones?.length ? config.tones : categoricalTones;
    const colors = tones.map(toneColor);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isCircular = config.type === 'donut';

    const options = {
        chart: {
            type: config.type,
            height: config.height,
            fontFamily: "'Somar Sans', ui-sans-serif, system-ui, sans-serif",
            toolbar: { show: false },
            zoom: { enabled: false },
            background: 'transparent',
            foreColor: text,
            animations: { enabled: !reducedMotion, speed: 420, animateGradually: { enabled: false } },
            parentHeightOffset: 0,
        },
        theme: { mode: dark ? 'dark' : 'light' },
        series: config.series,
        colors,
        dataLabels: { enabled: false },
        legend: { show: false },
        grid: {
            borderColor: grid,
            strokeDashArray: 3,
            padding: { top: -8, right: 8, left: 8, bottom: 0 },
        },
        stroke: { width: config.type === 'area' || config.type === 'line' ? 2 : 0, curve: 'straight', lineCap: 'round' },
        tooltip: {
            theme: dark ? 'dark' : 'light',
            y: { formatter: format.tooltip },
        },
        states: {
            hover: { filter: { type: 'darken', value: 0.9 } },
            active: { filter: { type: 'none' } },
        },
    };

    if (isCircular) {
        options.labels = config.categories;
        options.plotOptions = {
            pie: {
                expandOnClick: false,
                donut: {
                    size: '72%',
                    labels: {
                        show: true,
                        name: { show: true, fontSize: '12px', fontWeight: 500, color: text, offsetY: 18 },
                        value: {
                            show: true,
                            fontSize: '28px',
                            fontWeight: 700,
                            color: dark ? '#ffffff' : token('--color-secondary-dark'),
                            offsetY: -14,
                            formatter: (value) => format.tooltip(Number(value)),
                        },
                        total: {
                            show: true,
                            showAlways: true,
                            label: config.totalLabel ?? '',
                            fontSize: '12px',
                            fontWeight: 500,
                            color: text,
                            formatter: (w) => format.tooltip(w.globals.seriesTotals.reduce((sum, value) => sum + value, 0)),
                        },
                    },
                },
            },
        };

        return options;
    }

    options.xaxis = {
        categories: config.categories,
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { colors: text, fontSize: '11px' }, formatter: config.horizontal ? format.axis : undefined },
        tooltip: { enabled: false },
        // Every value axis in this app plots whole counts or currency
        // amounts, never fractions — without this ApexCharts' own "nice
        // scale" tick generator can land on .5 steps for a small max value.
        decimalsInFloat: 0,
    };
    options.yaxis = {
        labels: {
            style: { colors: text, fontSize: '11px' },
            formatter: config.horizontal ? undefined : format.axis,
            maxWidth: 120,
        },
        decimalsInFloat: 0,
    };

    if (config.type === 'bar') {
        options.plotOptions = {
            bar: {
                horizontal: Boolean(config.horizontal),
                borderRadius: 4,
                borderRadiusApplication: 'end',
                barHeight: '58%',
                columnWidth: '46%',
                distributed: Boolean(config.distributed),
            },
        };
        options.grid.xaxis = { lines: { show: Boolean(config.horizontal) } };
        options.grid.yaxis = { lines: { show: !config.horizontal } };
    }

    if (config.type === 'area') {
        options.fill = {
            type: 'gradient',
            gradient: { shadeIntensity: 0, opacityFrom: 0.28, opacityTo: 0.02, stops: [0, 100] },
        };
        options.markers = { size: 0, hover: { size: 5 } };
    }

    return options;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcChart', (config) => ({
        chart: null,
        observer: null,
        ready: false,

        destroyed: false,

        async init() {
            const { default: ApexCharts } = await import('apexcharts');

            // The element can leave the page (wire:navigate) while the import is pending.
            if (this.destroyed || !this.$refs.canvas) {
                return;
            }

            this.chart = new ApexCharts(this.$refs.canvas, buildOptions(config));
            await this.chart.render();

            if (this.destroyed) {
                this.chart.destroy();
                this.chart = null;

                return;
            }

            this.ready = true;

            this.observer = new MutationObserver(() => this.chart?.updateOptions(buildOptions(config), false, false));
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },

        destroy() {
            this.destroyed = true;
            this.observer?.disconnect();
            this.chart?.destroy();
            this.chart = null;
        },
    }));
});
