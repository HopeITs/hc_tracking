# 03 · Charts (ApexCharts + `x-ui.chart`)

**Owner group:** Foundation · **Wave:** 0 (after 01, parallel with 02) · **Blocks:** dashboard, executive summary, any page with a chart

## Files you own

- `resources/js/app.js`, new `resources/js/charts.js`
- `resources/views/components/ui/chart.blade.php`
- chart strings in `lang/*/ui.php` (coordinate with 02 owner, same file)

`apexcharts@^4.7.0` is already installed (`package.json`). Don't add other chart libraries.

## 1. JS setup

`resources/js/charts.js`:

- `import ApexCharts from 'apexcharts'`.
- On `document.addEventListener('alpine:init', ...)` register `Alpine.data('hcChart', (config) => ({ ... }))`. Livewire bundles Alpine, so don't import Alpine.
- `init()`: read tokens with `getComputedStyle(document.documentElement)` (`--color-secondary-dark`, `--color-primary`, `--color-funding`, `--color-status-warn`, `--color-secondary-light`, `--color-status-good`, `--color-secondary-soft` for grid), build options, `new ApexCharts(this.$refs.canvas, options).render()`.
- Base options: `chart.fontFamily: 'Somar Sans'`, `toolbar.show: false`, `animations.enabled` = `!matchMedia('(prefers-reduced-motion: reduce)').matches`, `grid.borderColor` = soft token, `dataLabels.enabled: false`, `legend.position: 'bottom'`, `tooltip.theme` from dark mode, `stroke.width: 2`, area fill opacity 0.12 (flat, no gradient), bar `borderRadius: 2`.
- Numbers: `yaxis.labels.formatter` / tooltip formatter use `new Intl.NumberFormat(document.documentElement.lang)`; money charts pass `config.currency` and format with that currency code as suffix (never mix currencies in one series).
- Dark mode: `this.$watch('$flux.dark', ...)` → `chart.updateOptions({ theme: { mode }, grid, xaxis/yaxis label colours })`.
- **RTL:** chart canvas container gets `dir="ltr"` so axes keep chronological left→right order; all labels, legends and tooltips are still translated. (Decision: charts read LTR in both languages.)
- `destroy()`: `this.chart?.destroy()`. Also destroy on `document.addEventListener('livewire:navigating')`.
- `app.js`: `import './charts'`.

## 2. `x-ui.chart` contract

```blade
<x-ui.chart
    type="donut"                         {{-- area | line | bar | donut | radialBar | heatmap | rangeBar --}}
    :label="__('dashboard.projects_by_status')"   {{-- figcaption + aria --}}
    :series="[12, 5, 3]"                 {{-- Apex series shape for the type --}}
    :categories="['Active', 'Draft', 'Closed']"   {{-- labels for donut/radialBar, x categories otherwise --}}
    :tones="['good', 'neutral', 'critical']"      {{-- optional: map series/slices to tone tokens; default = categorical order --}}
    :height="260"
    :currency="null"                     {{-- ?string: money formatting for all values --}}
    :options="[]"                        {{-- deep-merged escape hatch; keep use rare --}}
    :empty="__('dashboard.no_projects')" {{-- shown instead of chart when all values are 0/empty --}}
/>
```

Render:

```blade
<figure class="..." role="group" aria-labelledby="chart-{{ $id }}-caption">
  <figcaption id="chart-{{ $id }}-caption" class="type-heading">{{ $label }}</figcaption>
  <div wire:ignore wire:key="chart-{{ $id }}-{{ md5(json_encode([$series, $categories])) }}"
       x-data="hcChart(@js($config))" dir="ltr">
     <div x-ref="canvas" style="min-height: {{ $height }}px"></div>
  </div>
  <table class="sr-only"> ...series × categories as a real table for screen readers... </table>
</figure>
```

- The `wire:key` includes a hash of the data, so a Livewire re-render with new data replaces the chart (no stale canvas).
- Reserve `min-height` to avoid layout shift. Show a skeleton bar (`secondary-soft`) until init.
- `rangeBar` (timeline) and `heatmap` are available, but prefer `x-ui.timeline` and `x-ui.heat-cell` for server-rendered, RTL-native versions. Use Apex for them only when interaction (zoom/tooltip over many rows) is needed.

## 3. Tests

- `UiComponentsTest`: chart renders figcaption, `x-data="hcChart(`, the sr-only table containing each category label, and the `empty` text when all values are zero.

## Acceptance

- [ ] Chart renders in light and dark, switches live on theme toggle.
- [ ] Survives `wire:navigate` away and back (no duplicate canvases, no console errors).
- [ ] Re-renders on Livewire filter change.
- [ ] Reduced-motion disables animation.
- [ ] `npm run build` bundle size noted in review (ApexCharts ≈ 150KB gzip); if needed, lazy-import in `init()` via `await import('apexcharts')`.
