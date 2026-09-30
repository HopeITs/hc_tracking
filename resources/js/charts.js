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

/**
 * Dark ("Graphite") replacements for the tones that disappear on charcoal.
 * The brand greys are mid-greys, so `strong` reads as supporting ink; and
 * `critical` plots status data (e.g. materialised risks), so it takes the
 * dark status red, not the brand red kept for primary actions.
 */
const darkToneTokens = {
    critical: '--color-status-critical',
    muted: '--color-secondary-medium',
    strong: '--color-ink-muted',
};

const categoricalTones = ['funding', 'critical', 'good', 'warn', 'strong', 'neutral'];

function isDark() {
    return document.documentElement.classList.contains('dark');
}

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function toneColor(tone) {
    const darkToken = isDark() ? darkToneTokens[tone] : undefined;

    return token(darkToken ?? toneTokens[tone] ?? toneTokens.neutral);
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
    const text = dark ? token('--color-ink-faint') : token('--color-secondary-medium');
    const grid = dark ? token('--color-line') : token('--color-secondary-soft');
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

        // ApexCharts fills donut tooltips with the series colour (its donut default, kept in light); the pale
        // dark tones would sit under ink text. Set in both modes so a live theme flip never keeps the other value.
        options.tooltip.fillSeriesColor = !dark;
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
                            color: dark ? token('--color-ink') : token('--color-secondary-dark'),
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

    // Every value axis in this app plots whole counts or currency amounts,
    // never fractions. ApexCharts' own "nice scale" tick generator can still
    // land on fractional steps (e.g. 0, 0.2, 0.4 ... for a max of 2) — and
    // since we supply our own axis label `formatter` above, its
    // `decimalsInFloat` option has no effect (a custom formatter bypasses
    // it entirely). So the tick positions themselves are pinned to whole
    // numbers instead, by picking an integer step and sizing the axis to a
    // multiple of it.
    const valueAxisValues = (config.series ?? []).flatMap((entry) => (Array.isArray(entry?.data) ? entry.data : []));
    const valueAxisMax = valueAxisValues.length ? Math.max(...valueAxisValues) : 0;
    let valueAxisBounds = {};

    if (Number.isFinite(valueAxisMax) && valueAxisMax > 0) {
        const step = Math.max(1, Math.ceil(valueAxisMax / 6));
        const tickAmount = Math.ceil(valueAxisMax / step);

        valueAxisBounds = { min: 0, max: tickAmount * step, tickAmount };
    }

    options.xaxis = {
        categories: config.categories,
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { colors: text, fontSize: '11px' }, formatter: config.horizontal ? format.axis : undefined },
        tooltip: { enabled: false },
        ...(config.horizontal ? valueAxisBounds : {}),
    };
    options.yaxis = {
        labels: {
            style: { colors: text, fontSize: '11px' },
            formatter: config.horizontal ? undefined : format.axis,
            maxWidth: 120,
        },
        ...(config.horizontal ? {} : valueAxisBounds),
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

/**
 * Every rendered chart, re-themed together by one observer on <html class>.
 * Other classes on <html> come and go too (print mode, scroll lock), so each
 * entry remembers the theme it was drawn in and only a real light/dark flip
 * redraws it.
 *
 * @type {Set<{chart: object|null, config: object, element: HTMLElement, dark: boolean}>}
 */
const mountedCharts = new Set();
let themeObserver = null;

/** Redraw a chart with freshly read tokens when the theme flipped since it was drawn. */
function drawInCurrentTheme(mounted) {
    const dark = isDark();

    if (mounted.chart === null || mounted.dark === dark) {
        return;
    }

    mounted.dark = dark;

    // No redraw from scratch and no animation: only the colours change.
    mounted.chart.updateOptions(buildOptions(mounted.config), false, false);
}

function watchTheme() {
    if (themeObserver !== null) {
        return;
    }

    themeObserver = new MutationObserver(() => mountedCharts.forEach(drawInCurrentTheme));
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}

function releaseChart(mounted) {
    mountedCharts.delete(mounted);
    mounted.chart?.destroy();
    mounted.chart = null;
}

// wire:navigate swaps the page without a reload. Alpine destroys the old
// charts; this only drops any that left the page without being destroyed.
document.addEventListener('livewire:navigated', () => {
    mountedCharts.forEach((mounted) => {
        if (!mounted.element.isConnected) {
            releaseChart(mounted);
        }
    });
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcChart', (config) => {
        // Kept out of Alpine's reactive data: the shared theme observer finds
        // the chart by identity, and Alpine hands each method a new proxy.
        const mounted = { chart: null, config, element: null, dark: false };

        return {
            ready: false,

            destroyed: false,

            async init() {
                const { default: ApexCharts } = await import('apexcharts');

                // The element can leave the page (wire:navigate) while the import is pending.
                if (this.destroyed || !this.$refs.canvas) {
                    return;
                }

                mounted.element = this.$refs.canvas;
                mounted.dark = isDark();
                mounted.chart = new ApexCharts(this.$refs.canvas, buildOptions(config));
                await mounted.chart.render();

                if (this.destroyed) {
                    releaseChart(mounted);

                    return;
                }

                this.ready = true;

                mountedCharts.add(mounted);
                watchTheme();

                // The theme can flip while the chart is still drawing.
                drawInCurrentTheme(mounted);
            },

            destroy() {
                this.destroyed = true;
                releaseChart(mounted);
            },
        };
    });
});
