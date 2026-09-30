import { toDay, toIso, weekday, workingDays, unitCells, topCells, WEEKEND_PRESETS, ZOOM_LEVELS } from './timescale';
import { linkPath, routePath } from './links';
import { buildGraph, traceRoute, ancestorsOf, descendantsOf, linkKey } from './trace';
import { packLanes, horizonUnit } from './overview';
import { exportCsv, printChart } from './export';

/**
 * The Gantt engine: an Alpine component that takes the plain payload the
 * server builds (App\Livewire\Concerns\BuildsProjectGantt and friends) and
 * draws an MS-Project-style split grid + timeline.
 *
 * Read-only: WBS tree, five zoom levels, outline levels, working-week shading,
 * critical path, slip tails, baselines, planned vs actual, search/filters,
 * column chooser, two row densities, virtualized rows, export.
 * Explorer (the Timeline page): a signal line, the horizon band (the whole
 * plan in one strip with a draggable window), route tracing (select a row and
 * everything that drives it, depends on it, contains it or pays for it lights
 * up) and an inspector that lists those connections.
 * Editable (project page): drag/resize/progress, inline cells, indent/outdent,
 * dependency links drawn bar-to-bar, working week, baselines, and an undo
 * stack that reverts every server-side ripple (auto-scheduled successors too).
 *
 * Every edit is applied optimistically, then replaced by the payload the
 * server answers with, so the chart always ends on what the database holds.
 *
 * Viewer preferences (zoom, grid width, columns, density, folded rows,
 * display toggles) stay in this browser; the working week is the project's
 * and lives on the server.
 */

const ROW_HEIGHTS = { comfortable: 38, compact: 30 };
const BAR_HEIGHTS = { comfortable: 18, compact: 14 };
const OVERSCAN = 8;
const PAD_DAYS = 14;
const HEADER_HEIGHT = 56;
const STORAGE_KEY = 'hc-gantt';
const HORIZON_LANES = 6;
const BAR_LABELS = ['name', 'owner', 'none'];

/** Grid column widths in rem; WBS and name always show, the rest are optional. */
const COLUMN_WIDTHS = { wbs: 3.25, name: 14, status: 6.5, owner: 7.5, start: 4.75, finish: 4.75, duration: 3, progress: 4.75, predecessors: 4.5, float: 3.25, variance: 3 };
const OPTIONAL_COLUMNS = ['status', 'owner', 'start', 'finish', 'duration', 'progress', 'predecessors', 'float'];
const DEFAULT_COLUMNS = {
    explorer: { status: true, owner: false, start: true, finish: true, duration: false, progress: true, predecessors: false, float: false },
    standard: { status: false, owner: false, start: true, finish: true, duration: true, progress: true, predecessors: true, float: false },
};

function readPreferences(scope) {
    try {
        return JSON.parse(window.localStorage.getItem(`${STORAGE_KEY}:${scope}`) ?? '{}');
    } catch {
        return {};
    }
}

function writePreferences(scope, value) {
    try {
        window.localStorage.setItem(`${STORAGE_KEY}:${scope}`, JSON.stringify(value));
    } catch {
        // Storage can be unavailable (private window); preferences are a convenience only.
    }
}

/** An undo step that puts back every row a server edit moved. */
function restoreStep(result) {
    return result.changes?.length ? [{ method: 'ganttRestore', args: [result.changes] }] : [];
}

function isTyping(target) {
    return Boolean(target?.closest?.('input, select, textarea, [contenteditable="true"]'));
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('ganttChart', (payload, scope = 'default', editable = false, labels = {}, options = {}) => ({
        // Derived structures (graph, visible rows, trace, signals, horizon) are
        // cached here. The holder cannot be extended, so Alpine never makes it
        // reactive; each cache is keyed by the reactive values it depends on.
        _memo: Object.preventExtensions({ store: {} }),
        editable,
        explorer: Boolean(options.explorer),
        labels,
        scope,
        tones: options.tones ?? { bar: {}, track: {}, edge: {}, pill: {} },
        groupBy: options.groupBy ?? null,
        rows: [],
        links: [],
        relations: [],
        baselines: [],
        baselineId: null,
        rowsVersion: 0,
        collapsed: {},
        outlineLevel: null,
        zoom: options.explorer ? 'month' : 'week',
        weekendPreset: payload.weekend ?? 'sat-sun',
        gridWidth: 0,
        fullGridWidth: null,
        density: 'comfortable',
        columns: { ...DEFAULT_COLUMNS[options.explorer ? 'explorer' : 'standard'] },
        barLabels: options.explorer ? 'name' : 'owner',
        search: '',
        lateOnly: false,
        criticalOnly: false,
        dueWindow: 0,
        showCritical: true,
        showLinks: true,
        trace: true,
        routes: 'selected',
        showHorizon: true,
        selectedId: null,
        inspectorOpen: false,
        shortcutsOpen: false,
        hover: null,
        scrollTop: 0,
        scrollLeft: 0,
        viewportHeight: 480,
        viewportWidth: 960,
        bandWidth: 0,
        printing: false,
        settled: false,
        locale: document.documentElement.lang || 'en',
        todayDay: toDay(payload.today),
        fromDay: 0,
        toDay: 0,

        // Editing state
        undoStack: [],
        redoStack: [],
        busy: false,
        drag: null,
        editing: null,
        linkDraft: null,
        linkMenu: null,
        baselineName: '',

        init() {
            const saved = readPreferences(scope);

            this.zoom = ZOOM_LEVELS[saved.zoom] ? saved.zoom : this.zoom;
            this.gridWidth = Number(saved.gridWidth) || 0;
            this.collapsed = saved.collapsed ?? {};
            this.showCritical = saved.showCritical ?? true;
            this.showLinks = saved.showLinks ?? true;
            this.density = ROW_HEIGHTS[saved.density] ? saved.density : this.density;
            this.barLabels = BAR_LABELS.includes(saved.barLabels) ? saved.barLabels : this.barLabels;
            this.trace = saved.trace ?? true;
            this.routes = saved.routes === 'all' ? 'all' : 'selected';
            this.showHorizon = saved.showHorizon ?? true;

            for (const column of OPTIONAL_COLUMNS) {
                if (typeof saved.columns?.[column] === 'boolean') {
                    this.columns[column] = saved.columns[column];
                }
            }

            if (!this.editable && WEEKEND_PRESETS[saved.weekend]) {
                this.weekendPreset = saved.weekend;
            }

            this.load(payload);

            if (!this.gridWidth) {
                this.gridWidth = this.naturalGridWidth();
            }

            for (const setting of ['zoom', 'showCritical', 'showLinks', 'density', 'barLabels', 'trace', 'routes', 'showHorizon']) {
                this.$watch(setting, () => this.persist());
            }

            this.$watch('showHorizon', () => this.$nextTick(() => this.measure()));
            this.$watch('inspectorOpen', () => this.$nextTick(() => this.measure()));
            this.$watch('compactGrid', (compact) => this.fitGrid(compact));

            // After a search or filter changes, bring the selected row back into view.
            for (const filter of ['search', 'lateOnly', 'criticalOnly', 'dueWindow']) {
                this.$watch(filter, () => this.$nextTick(() => this.revealSelected()));
            }

            this.$nextTick(() => {
                if (!saved.gridWidth && this.$refs.scroller) {
                    // A first visit leaves the timeline at least half the width.
                    this.gridWidth = Math.min(this.gridWidth, Math.round(this.$refs.scroller.clientWidth * (this.explorer ? 0.46 : 0.6)));
                }

                this.measure();
                this.scrollToDay(this.todayDay - (this.explorer ? 45 : 7), false);
            });

            // Bars draw in once on arrival; rows scrolled into view later just appear.
            setTimeout(() => (this.settled = true), 1400);
        },

        load(data) {
            const parents = new Set(data.rows.map((row) => row.parent));

            this.rows = data.rows.map((row) => ({
                ...row,
                startDay: toDay(row.start),
                endDay: toDay(row.end),
                actualStartDay: toDay(row.actualStart),
                actualEndDay: toDay(row.actualEnd),
                baselineStartDay: toDay(row.baselineStart ?? null),
                baselineEndDay: toDay(row.baselineEnd ?? null),
                hasChildren: parents.has(row.id),
                late: row.tone === 'critical',
            }));

            this.links = data.links ?? [];
            this.relations = data.relations ?? [];
            this.baselines = data.baselines ?? [];
            this.baselineId = data.baselineId ?? null;
            this.rowsVersion++;

            if (this.selectedId !== null && !this.rows.some((row) => row.id === this.selectedId)) {
                this.selectedId = null;
                this.inspectorOpen = false;
            }

            if (this.editable && data.weekend) {
                this.weekendPreset = data.weekend;
            }

            const from = toDay(data.from) ?? this.todayDay;
            const to = toDay(data.to) ?? this.todayDay;

            // Only ever widen the window on reload, so a save never shifts what the viewer is looking at.
            const loaded = this.toDay !== 0;

            this.fromDay = loaded ? Math.min(this.fromDay, from - PAD_DAYS) : from - PAD_DAYS;
            this.toDay = loaded ? Math.max(this.toDay, to + PAD_DAYS * 2) : to + PAD_DAYS * 2;
        },

        persist() {
            writePreferences(scope, {
                zoom: this.zoom,
                weekend: this.weekendPreset,
                gridWidth: this.fullGridWidth ?? this.gridWidth,
                collapsed: this.collapsed,
                showCritical: this.showCritical,
                showLinks: this.showLinks,
                density: this.density,
                columns: this.columns,
                barLabels: this.barLabels,
                trace: this.trace,
                routes: this.routes,
                showHorizon: this.showHorizon,
            });
        },

        measure() {
            const scroller = this.$refs.scroller;

            if (!scroller) {
                return;
            }

            this.viewportHeight = scroller.clientHeight;
            this.viewportWidth = scroller.clientWidth;
            this.bandWidth = this.$refs.horizon?.clientWidth ?? 0;
            // Never let the table crowd out the timeline on a narrow screen.
            this.gridWidth = Math.min(this.gridWidth, Math.max(200, Math.round(scroller.clientWidth * 0.6)));
        },

        onScroll(event) {
            this.scrollTop = event.target.scrollTop;
            this.scrollLeft = event.target.scrollLeft;
        },

        /* Geometry ------------------------------------------------------- */

        get ppd() {
            return ZOOM_LEVELS[this.zoom].ppd;
        },

        get rowHeight() {
            return ROW_HEIGHTS[this.density] ?? ROW_HEIGHTS.comfortable;
        },

        get barHeight() {
            return BAR_HEIGHTS[this.density] ?? BAR_HEIGHTS.comfortable;
        },

        get weekend() {
            return WEEKEND_PRESETS[this.weekendPreset] ?? WEEKEND_PRESETS['sat-sun'];
        },

        get weekStart() {
            // The week starts on the day after the last weekend day (Monday for Sat–Sun).
            const weekend = this.weekend;

            return weekend.length === 0 ? 1 : (Math.max(...weekend.map((day) => (day === 0 ? 7 : day))) + 1) % 7;
        },

        get chartWidth() {
            return Math.ceil((this.toDay - this.fromDay + 1) * this.ppd);
        },

        get hasBaseline() {
            return this.baselineId !== null;
        },

        /** While the inspector is open, or on a phone, the table narrows to WBS and name so the timeline keeps its room. */
        get compactGrid() {
            return (this.explorer && this.inspectorOpen && this.selectedId !== null) || this.viewportWidth < 640;
        },

        /** Phones drop the WBS numbers and tighten the outline indent so names stay readable. */
        get showWbs() {
            return this.viewportWidth >= 640;
        },

        get indent() {
            return this.showWbs ? 14 : 9;
        },

        /** The optional columns on screen right now. */
        get shown() {
            return this.compactGrid ? {} : this.columns;
        },

        fitGrid(compact) {
            if (compact) {
                const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;

                this.fullGridWidth ??= this.gridWidth;
                this.gridWidth = Math.min(this.gridWidth, Math.round((COLUMN_WIDTHS.wbs + 12.5) * rem + 24));
            } else if (this.fullGridWidth !== null) {
                this.gridWidth = this.fullGridWidth;
                this.fullGridWidth = null;
            }

            this.$nextTick(() => this.measure());
        },

        /** Grid columns: WBS · name · [status · owner · start · finish · duration · % · predecessors · float] [· variance]. */
        get gridTemplate() {
            const widths = this.showWbs ? [`${COLUMN_WIDTHS.wbs}rem`, 'minmax(9rem, 1fr)'] : ['minmax(0, 1fr)'];

            for (const column of OPTIONAL_COLUMNS) {
                if (this.shown[column]) {
                    widths.push(`${COLUMN_WIDTHS[column]}rem`);
                }
            }

            if (this.hasBaseline) {
                widths.push(`${COLUMN_WIDTHS.variance}rem`);
            }

            return `grid-template-columns:${widths.join(' ')}`;
        },

        /** The grid width that shows every chosen column with a comfortable name column. */
        naturalGridWidth() {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
            const chosen = OPTIONAL_COLUMNS.filter((column) => this.columns[column]);
            const total = COLUMN_WIDTHS.wbs + COLUMN_WIDTHS.name + chosen.reduce((sum, column) => sum + COLUMN_WIDTHS[column], 0) + (chosen.length + 1) * 0.5;

            return Math.round(total * rem + 24);
        },

        toggleColumn(column) {
            this.columns = { ...this.columns, [column]: !this.columns[column] };
            this.gridWidth = this.naturalGridWidth();
            this.measure();
            this.persist();
        },

        get topTier() {
            return topCells(this.fromDay, this.toDay, ZOOM_LEVELS[this.zoom].top, this.locale, this.weekStart);
        },

        get bottomTier() {
            return unitCells(this.fromDay, this.toDay, ZOOM_LEVELS[this.zoom].bottom, this.locale, this.weekStart);
        },

        /** Non-working day stripes, only where a day is wide enough to see. */
        get weekendStripes() {
            if (this.ppd < 10 || this.weekend.length === 0) {
                return [];
            }

            const stripes = [];

            for (let day = this.fromDay; day <= this.toDay; day++) {
                if (this.weekend.includes(weekday(day))) {
                    stripes.push(day);
                }
            }

            return stripes;
        },

        /** Rows, links and relations indexed for walking. */
        get graph() {
            if (this._memo.store.graph?.version !== this.rowsVersion) {
                this._memo.store.graph = { version: this.rowsVersion, value: buildGraph(this.rows, this.links, this.relations) };
            }

            return this._memo.store.graph.value;
        },

        /** Rows that survive folding, search and the late / critical / due filters. */
        get visibleRows() {
            const key = [this.rowsVersion, this.search, this.lateOnly, this.criticalOnly, this.dueWindow, this.printing].join('|');
            const collapsed = this.collapsed;

            if (this._memo.store.visible?.key === key && this._memo.store.visible.collapsed === collapsed) {
                return this._memo.store.visible.rows;
            }

            const rows = this.filterRows(collapsed);

            this._memo.store.visible = { key, collapsed, rows };

            return rows;
        },

        get filtering() {
            return this.search.trim() !== '' || this.lateOnly || this.criticalOnly || this.dueWindow > 0;
        },

        filterRows(collapsed) {
            const term = this.search.trim().toLowerCase();
            const byId = this.graph.byId;
            const isHidden = (row) => {
                for (let parent = byId[row.parent]; parent; parent = byId[parent.parent]) {
                    if (collapsed[parent.id] && !this.printing) {
                        return true;
                    }
                }

                return false;
            };

            if (!this.filtering) {
                return this.rows.filter((row) => !isHidden(row));
            }

            // When filtering, keep every match plus its ancestors so the WBS context stays readable.
            const keep = new Set();
            const today = this.todayDay;

            for (const row of this.rows) {
                const matchesTerm = term === '' || row.name.toLowerCase().includes(term) || (row.owner ?? '').toLowerCase().includes(term);
                const matchesLate = !this.lateOnly || (row.late && row.progress < 100);
                const matchesCritical = !this.criticalOnly || row.critical;
                const matchesDue = this.dueWindow === 0 || (row.progress < 100 && row.endDay !== null && row.endDay >= today && row.endDay <= today + this.dueWindow);

                if (matchesTerm && matchesLate && matchesCritical && matchesDue && row.kind !== 'group') {
                    keep.add(row.id);

                    for (let parent = byId[row.parent]; parent; parent = byId[parent.parent]) {
                        keep.add(parent.id);
                    }
                }
            }

            return this.rows.filter((row) => keep.has(row.id));
        },

        clearFilters() {
            this.search = '';
            this.lateOnly = false;
            this.criticalOnly = false;
            this.dueWindow = 0;
        },

        get rowIndex() {
            const rows = this.visibleRows;

            if (this._memo.store.rowIndex?.rows !== rows) {
                this._memo.store.rowIndex = { rows, value: Object.fromEntries(rows.map((row, index) => [row.id, index])) };
            }

            return this._memo.store.rowIndex.value;
        },

        get windowed() {
            const rows = this.visibleRows;
            const height = this.rowHeight;

            if (this.printing) {
                return rows.map((row, index) => ({ row, index }));
            }

            const first = Math.max(0, Math.floor(this.scrollTop / height) - OVERSCAN);
            const last = Math.min(rows.length, Math.ceil((this.scrollTop + this.viewportHeight) / height) + OVERSCAN);

            return rows.slice(first, last).map((row, offset) => ({ row, index: first + offset }));
        },

        get bodyHeight() {
            return this.visibleRows.length * this.rowHeight;
        },

        x(dayNumber) {
            return (dayNumber - this.fromDay) * this.ppd;
        },

        barStyle(row) {
            if (row.startDay === null) {
                return 'display:none';
            }

            const width = Math.max(this.ppd, (row.endDay - row.startDay + 1) * this.ppd);

            return `left:${this.x(row.startDay)}px;width:${width}px`;
        },

        /** What actually happened: actual start (or planned) to actual end, or today while still open. */
        actualStyle(row) {
            if (row.actualStartDay === null && row.actualEndDay === null) {
                return 'display:none';
            }

            const start = row.actualStartDay ?? row.startDay;
            const end = row.actualEndDay ?? Math.max(start, this.todayDay);

            if (start === null) {
                return 'display:none';
            }

            return `left:${this.x(start)}px;width:${Math.max(this.ppd, (end - start + 1) * this.ppd)}px`;
        },

        baselineStyle(row) {
            if (!this.hasBaseline || row.baselineStartDay === null) {
                return 'display:none';
            }

            return `left:${this.x(row.baselineStartDay)}px;width:${Math.max(this.ppd, (row.baselineEndDay - row.baselineStartDay + 1) * this.ppd)}px`;
        },

        diamondStyle(row) {
            return `left:${this.x(row.startDay) + this.ppd / 2}px`;
        },

        /** Whether the row is unfinished and already past its planned finish. */
        isSlipping(row) {
            return row.late && row.kind !== 'group' && row.progress < 100 && row.endDay !== null && row.endDay < this.todayDay;
        },

        /** The hatched tail from a late row's planned finish up to today. */
        slipStyle(row) {
            if (!this.isSlipping(row)) {
                return 'display:none';
            }

            const from = row.diamond ? this.x(row.startDay) + this.ppd / 2 + 7 : this.x(row.endDay + 1);

            return `left:${from}px;width:${Math.max(2, this.x(this.todayDay + 1) - from)}px`;
        },

        daysLate(row) {
            return this.isSlipping(row) ? this.todayDay - row.endDay : 0;
        },

        /** The label beside a bar sits after its slip tail, when it has one. */
        labelStyle(row) {
            if (row.startDay === null || row.kind === 'group') {
                return 'display:none';
            }

            let left = row.diamond ? this.x(row.startDay) + this.ppd / 2 + 12 : this.x(row.endDay + 1) + 8;

            if (this.isSlipping(row)) {
                left = Math.max(left, this.x(this.todayDay + 1) + 8);
            }

            return `left:${left}px`;
        },

        barLabel(row) {
            if (this.barLabels === 'none') {
                return '';
            }

            if (this.barLabels === 'owner') {
                return row.owner ?? '';
            }

            return row.diamond ? `${row.name} · ${this.formatShort(row.startDay)}` : row.name;
        },

        groupStyle(row) {
            if (row.startDay === null) {
                return 'display:none';
            }

            return `left:${this.x(row.startDay)}px;width:${Math.max(this.ppd, (row.endDay - row.startDay + 1) * this.ppd)}px`;
        },

        duration(row) {
            if (row.startDay === null || row.endDay === null) {
                return '—';
            }

            return row.diamond ? 0 : workingDays(row.startDay, row.endDay, this.weekend);
        },

        /** Finish variance against the shown baseline, in working days (positive = late). */
        variance(row) {
            if (!this.hasBaseline || row.baselineEndDay === null || row.endDay === null) {
                return null;
            }

            if (row.endDay === row.baselineEndDay) {
                return 0;
            }

            const sign = row.endDay > row.baselineEndDay ? 1 : -1;
            const [from, to] = sign > 0 ? [row.baselineEndDay + 1, row.endDay] : [row.endDay + 1, row.baselineEndDay];

            return sign * workingDays(from, to, this.weekend);
        },

        /** Positive when the work finished (or is still running) past its planned end. */
        slip(row) {
            if (row.endDay === null || (row.progress >= 100 && row.actualEndDay === null)) {
                return 0;
            }

            const finish = row.actualEndDay ?? (row.progress < 100 ? this.todayDay : row.endDay);

            return Math.max(0, finish - row.endDay);
        },

        /** How far through its planned span today falls, 0–100. */
        elapsed(row) {
            if (row.startDay === null || row.endDay === null) {
                return 0;
            }

            return Math.round(Math.min(1, Math.max(0, (this.todayDay - row.startDay + 1) / (row.endDay - row.startDay + 1))) * 100);
        },

        formatDay(dayNumber) {
            if (dayNumber === null || dayNumber === undefined) {
                return '—';
            }

            return new Intl.DateTimeFormat(this.locale, { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(toIso(dayNumber)));
        },

        formatShort(dayNumber) {
            if (dayNumber === null || dayNumber === undefined) {
                return '—';
            }

            return new Intl.DateTimeFormat(this.locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(toIso(dayNumber)));
        },

        formatMonth(dayNumber) {
            if (dayNumber === null || dayNumber === undefined) {
                return '—';
            }

            return new Intl.DateTimeFormat(this.locale, { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(toIso(dayNumber)));
        },

        initials(name) {
            return String(name ?? '')
                .split(/[\s,]+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part[0])
                .join('')
                .toUpperCase();
        },

        isCriticalShown(row) {
            return this.showCritical && row.critical === true;
        },

        /** The outline a bar wears: the traced route first, then the critical path. */
        barRing(row) {
            const role = this.roleOf(row);

            if (role === 'self') {
                return 'ring-2 ring-status-active ring-offset-2 ring-offset-white dark:ring-offset-surface-1';
            }

            if (role === 'up' || role === 'down') {
                return 'ring-2 ring-status-active/60';
            }

            if (role === 'money') {
                return 'ring-2 ring-funding/70';
            }

            if (this.isCriticalShown(row)) {
                return 'ring-2 ring-status-critical ring-offset-1 ring-offset-white dark:ring-offset-surface-1';
            }

            return `ring-1 ring-inset ${this.tones.edge?.[row.tone] ?? ''}`;
        },

        diamondRing(row) {
            const role = this.roleOf(row);

            if (role === 'self' || role === 'up' || role === 'down') {
                return 'ring-2 ring-status-active';
            }

            if (role === 'money') {
                return 'ring-2 ring-funding';
            }

            return this.isCriticalShown(row) ? 'ring-2 ring-status-critical' : 'ring-2 ring-white dark:ring-surface-1';
        },

        /** Which glyph a row's name wears in the grid. */
        iconKey(row) {
            if (row.kind !== 'group') {
                return row.kind;
            }

            if (row.id === 'fundraising') {
                return 'money';
            }

            return row.id === 'obligations' ? 'calendar' : 'folder';
        },

        /* Signals and the horizon band (explorer) ------------------------ */

        /** One reading of the whole plan: its span, how much is done, and what needs attention. */
        get signals() {
            const key = `${this.rowsVersion}|${this.todayDay}`;

            if (this._memo.store.signals?.key === key) {
                return this._memo.store.signals.value;
            }

            const today = this.todayDay;
            const work = this.rows.filter((row) => row.kind !== 'group');
            const dated = work.filter((row) => row.startDay !== null);
            const projects = work.filter((row) => row.kind === 'project' && !String(row.id).startsWith('funded-'));
            const records = (projects.length > 0 ? projects : work.filter((row) => row.kind === 'proposal')).filter((row) => row.startDay !== null);
            const open = (row) => row.progress < 100 && row.endDay !== null;

            let weight = 0;
            let done = 0;

            for (const row of records) {
                const days = row.endDay - row.startDay + 1;

                weight += days;
                done += days * row.progress;
            }

            const start = dated.length ? Math.min(...dated.map((row) => row.startDay)) : null;
            const end = dated.length ? Math.max(...dated.map((row) => row.endDay)) : null;

            const value = {
                start,
                end,
                progress: weight > 0 ? Math.round(done / weight) : 0,
                elapsed: start === null ? 0 : Math.round(Math.min(1, Math.max(0, (today - start + 1) / (end - start + 1))) * 100),
                records: records.length,
                late: work.filter((row) => row.late && row.progress < 100).length,
                critical: work.filter((row) => row.critical).length,
                due30: work.filter((row) => open(row) && row.endDay >= today && row.endDay <= today + 30).length,
                due90: work.filter((row) => open(row) && row.endDay >= today && row.endDay <= today + 90).length,
                fundingLinks: this.relations.length,
            };

            // Work trailing the calendar by more than ten points reads as behind schedule.
            value.behind = records.length > 0 && value.elapsed - value.progress > 10 && value.elapsed < 100;

            this._memo.store.signals = { key, value };

            return value;
        },

        toggleDue(days) {
            this.dueWindow = this.dueWindow === days ? 0 : days;

            if (this.dueWindow > 0) {
                this.$nextTick(() => this.scrollToDay(this.todayDay - 3, true));
            }
        },

        /** The whole plan squeezed into the band: packed record lanes, point ticks and calendar labels. */
        get horizon() {
            const width = this.bandWidth;
            const key = `${this.rowsVersion}|${width}|${this.fromDay}|${this.toDay}`;

            if (this._memo.store.horizon?.key === key) {
                return this._memo.store.horizon.value;
            }

            if (!this.explorer || width <= 0) {
                return { lanes: 1, bars: [], ticks: [], years: [], units: [], scale: 0 };
            }

            const scale = width / (this.toDay - this.fromDay + 1);
            const place = (day) => (day - this.fromDay) * scale;
            const records = this.rows.filter(
                (row) => row.startDay !== null && ((row.kind === 'project' && !String(row.id).startsWith('funded-')) || row.kind === 'proposal'),
            );
            const packed = packLanes(
                records.map((row) => ({ id: row.id, start: row.startDay, end: row.endDay, tone: row.tone, kind: row.kind, name: row.name })),
                HORIZON_LANES,
                Math.ceil(3 / scale),
            );
            const unit = horizonUnit(scale);
            const yearFormat = new Intl.DateTimeFormat(this.locale, { year: 'numeric', timeZone: 'UTC' });

            const value = {
                lanes: packed.lanes,
                scale,
                bars: packed.items.map((item) => ({ ...item, left: place(item.start), width: Math.max(3, (item.end - item.start + 1) * scale) })),
                ticks: this.rows
                    .filter((row) => row.diamond && row.startDay !== null)
                    .map((row) => ({ id: row.id, left: place(row.startDay), tone: row.tone, kind: row.kind })),
                years: unitCells(this.fromDay, this.toDay, 'year', this.locale, this.weekStart).map((cell) => ({
                    key: cell.key,
                    left: Math.max(0, place(cell.start)),
                    label: yearFormat.format(new Date(toIso(Math.max(cell.start, this.fromDay)))),
                })),
                units:
                    unit === 'year'
                        ? []
                        : unitCells(this.fromDay, this.toDay, unit, this.locale, this.weekStart)
                              .filter((cell) => cell.start > this.fromDay)
                              .map((cell) => ({ key: cell.key, left: place(cell.start), width: cell.days * scale, label: cell.label })),
            };

            this._memo.store.horizon = { key, value };

            return value;
        },

        get horizonLaneHeight() {
            return this.horizon.lanes > 4 ? 5 : 7;
        },

        horizonPlace(day) {
            return (day - this.fromDay) * this.horizon.scale;
        },

        /** The band's window: the stretch of time the chart below is showing. */
        get horizonWindow() {
            const scale = this.horizon.scale;

            if (!scale) {
                return 'display:none';
            }

            const left = Math.max(0, (this.scrollLeft / this.ppd) * scale);
            const width = Math.max(10, ((this.viewportWidth - this.gridWidth) / this.ppd) * scale);

            return `left:${left}px;width:${Math.min(width, Math.max(10, this.bandWidth - left))}px`;
        },

        /** Press on the band to jump there, then keep dragging to pan. */
        horizonDown(event) {
            const scale = this.horizon.scale;
            const scroller = this.$refs.scroller;

            if (!scale || !scroller || event.button !== 0) {
                return;
            }

            event.preventDefault();

            const band = this.$refs.horizon.getBoundingClientRect();

            if (!event.target.closest('[data-horizon-window]')) {
                const day = this.fromDay + (event.clientX - band.left) / scale;

                this.scrollToDay(day, false, true);
            }

            const startX = event.clientX;
            const startScroll = scroller.scrollLeft;
            const move = (moveEvent) => {
                scroller.scrollLeft = startScroll + ((moveEvent.clientX - startX) / scale) * this.ppd;
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        horizonKeydown(event) {
            const scroller = this.$refs.scroller;
            const step = (event.shiftKey ? 90 : 30) * this.ppd;

            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                scroller.scrollBy({ left: event.key === 'ArrowLeft' ? -step : step, behavior: 'smooth' });
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                scroller.scrollTo({ left: event.key === 'Home' ? 0 : scroller.scrollWidth, behavior: 'smooth' });
            }
        },

        /* Route tracing and the inspector (explorer) --------------------- */

        /** Everything connected to the selected row, whether or not the chart dims the rest. */
        get route() {
            if (!this.explorer || this.selectedId === null) {
                return null;
            }

            const key = `${this.rowsVersion}|${this.selectedId}`;

            if (this._memo.store.route?.key !== key) {
                this._memo.store.route = { key, value: traceRoute(this.graph, this.selectedId) };
            }

            return this._memo.store.route.value;
        },

        /** The route the chart is highlighting, or null when nothing is traced. */
        get traced() {
            return this.trace ? this.route : null;
        },

        roleOf(row) {
            return this.traced?.roles.get(row.id) ?? null;
        },

        isDimmed(row) {
            const traced = this.traced;

            return traced !== null && !traced.roles.has(row.id);
        },

        /** The short tag a traced row wears to say why it lit up. */
        routeTag(row) {
            const role = this.roleOf(row);
            const tags = this.labels.roles ?? {};

            if (role === 'up' || role === 'down') {
                return tags[role] ?? role;
            }

            if (role === 'money') {
                if (row.kind === 'proposal') {
                    return tags.funds ?? role;
                }

                return row.kind === 'project' ? tags.cofunded ?? role : tags.obligation ?? role;
            }

            return '';
        },

        selectRow(rowId) {
            const graph = this.graph;
            const row = graph.byId[rowId];

            if (!row) {
                return;
            }

            const folded = ancestorsOf(graph, rowId).filter((ancestor) => this.collapsed[ancestor.id]);

            if (folded.length > 0) {
                const collapsed = { ...this.collapsed };

                folded.forEach((ancestor) => delete collapsed[ancestor.id]);
                this.collapsed = collapsed;
                this.outlineLevel = null;
                this.persist();
            }

            if (this.filtering && this.rowIndex[rowId] === undefined) {
                this.clearFilters();
            }

            this.selectedId = rowId;
            this.inspectorOpen = this.explorer;

            this.$nextTick(() => {
                const index = this.rowIndex[rowId];

                if (index !== undefined) {
                    this.ensureVisible(index, true);
                }

                if (row.startDay !== null) {
                    this.scrollToDay(row.startDay - 3);
                }
            });
        },

        onRowClick(row) {
            this.selectedId = row.id;
            this.inspectorOpen = this.explorer;
        },

        clearSelection() {
            this.selectedId = null;
            this.inspectorOpen = false;
        },

        linkCode(link) {
            const lag = link.lag ? ` ${link.lag > 0 ? '+' : ''}${link.lag}d` : '';

            return `${String(link.type).toUpperCase()}${lag}`;
        },

        /** Everything the inspector lists about the selected row. */
        get inspected() {
            if (!this.explorer || !this.inspectorOpen || this.selectedId === null) {
                return null;
            }

            const graph = this.graph;
            const row = graph.byId[this.selectedId];

            if (!row) {
                return null;
            }

            const route = this.route;
            const roles = route ? [...route.roles.entries()] : [];
            const inRole = (role) => roles.filter(([, value]) => value === role).map(([id]) => graph.byId[id]).filter(Boolean);
            const byStart = (a, b) => (a.startDay ?? Infinity) - (b.startDay ?? Infinity);
            const money = inRole('money').sort(byStart);
            const parts = descendantsOf(graph, row.id).map((id) => graph.byId[id]).filter((part) => part && part.kind !== 'group');
            const children = (graph.children[row.id] ?? []).map((id) => graph.byId[id]);
            const connection = (link, id) => ({ row: graph.byId[id], code: this.linkCode(link), label: link.label });

            return {
                row,
                ancestors: ancestorsOf(graph, row.id),
                drivers: (graph.incoming[row.id] ?? []).map((link) => connection(link, link.from)).filter((entry) => entry.row),
                dependents: (graph.outgoing[row.id] ?? []).map((link) => connection(link, link.to)).filter((entry) => entry.row),
                upstream: inRole('up').length,
                downstream: inRole('down').length,
                proposals: money.filter((entry) => entry.kind === 'proposal'),
                funded: money.filter((entry) => entry.kind === 'project'),
                obligations: money.filter((entry) => entry.kind === 'transfer' || entry.kind === 'report'),
                children: children.slice(0, 8),
                moreChildren: Math.max(0, children.length - 8),
                parts: {
                    total: parts.length,
                    done: parts.filter((part) => part.progress >= 100).length,
                    late: parts.filter((part) => part.late && part.progress < 100).length,
                    critical: parts.filter((part) => part.critical).length,
                },
            };
        },

        openPlan(row) {
            if (row.planUrl) {
                window.Livewire?.navigate ? window.Livewire.navigate(row.planUrl) : (window.location.href = row.planUrl);
            }
        },

        /** Zoom so the whole bar fits, then bring it into view. */
        zoomToRow(row) {
            if (row.startDay === null) {
                return;
            }

            const available = this.viewportWidth - this.gridWidth - 48;
            const days = row.endDay - row.startDay + 1 + 6;
            const fitting = Object.entries(ZOOM_LEVELS).find(([, level]) => days * level.ppd <= available);

            this.zoom = fitting ? fitting[0] : 'year';
            this.$nextTick(() => this.scrollToDay(row.startDay - 3, true));
        },

        setGroup(grouping) {
            if (this.groupBy !== grouping) {
                this.groupBy = grouping;
                this.$wire.$set('group', grouping);
            }
        },

        /* Dependency arrows and funding routes ---------------------------- */

        /** Anchor x of a row's start or finish edge. */
        edgeX(row, side) {
            if (row.diamond) {
                return this.x(row.startDay) + this.ppd / 2 + (side === 'start' ? -7 : 7);
            }

            return side === 'start' ? this.x(row.startDay) : this.x(row.endDay + 1);
        },

        get drawnLinks() {
            if (!this.showLinks) {
                return [];
            }

            const index = this.rowIndex;
            const byId = this.graph.byId;
            const height = this.rowHeight;
            const traced = this.traced;

            return this.links
                .filter((link) => index[link.from] !== undefined && index[link.to] !== undefined && byId[link.from]?.startDay != null && byId[link.to]?.startDay != null)
                .map((link) => {
                    const from = byId[link.from];
                    const to = byId[link.to];
                    const fromSide = link.type[0] === 'f' ? 'end' : 'start';
                    const toSide = link.type[1] === 's' ? 'start' : 'end';
                    const onRoute = traced !== null && traced.links.has(linkKey(link));

                    return {
                        ...link,
                        onRoute,
                        faded: traced !== null && !onRoute,
                        critical: this.showCritical && from.critical && to.critical,
                        d: linkPath(
                            this.edgeX(from, fromSide),
                            index[link.from] * height + height / 2,
                            this.edgeX(to, toSide),
                            index[link.to] * height + height / 2,
                            fromSide,
                            toSide,
                            height,
                        ),
                    };
                });
        },

        /** SVG markup for every drawn arrow: a visible stroke plus a wide invisible hit path. */
        linksMarkup(markerScope) {
            const escape = (text) => String(text).replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);

            return this.drawnLinks
                .map((link, index) => {
                    let stroke = 'stroke-secondary-dark/65 dark:stroke-white/70';
                    let marker = `gantt-arrow-${markerScope}`;

                    if (link.onRoute) {
                        stroke = 'stroke-status-active';
                        marker = `gantt-arrow-active-${markerScope}`;
                    } else if (link.critical) {
                        stroke = 'stroke-status-critical';
                        marker = `gantt-arrow-critical-${markerScope}`;
                    }

                    const lag = link.lag ? ` ${link.lag > 0 ? '+' : ''}${link.lag}d` : '';
                    const title = `${this.rowName(link.from)} → ${this.rowName(link.to)} · ${link.label}${lag}`;
                    const hitClass = this.editable ? 'cursor-pointer' : '';
                    const faded = link.faded ? ' opacity="0.16"' : '';

                    return `<path d="${link.d}" fill="none" stroke-width="${link.onRoute ? 2.25 : 1.5}" stroke-linejoin="round" class="${stroke}"${faded} marker-end="url(#${escape(marker)})"></path>`
                        + `<path d="${link.d}" fill="none" stroke="transparent" stroke-width="10" class="${hitClass}" style="pointer-events:stroke" data-link-index="${index}"><title>${escape(title)}</title></path>`;
                })
                .join('');
        },

        /**
         * Funding routes: a proposal's line into each project it funds, and
         * each transfer or donor report dropped onto its proposal at its date.
         * Drawn for the traced row, or for every relation when asked to.
         */
        relationsMarkup(markerScope) {
            if (!this.explorer || this.relations.length === 0) {
                return '';
            }

            const traced = this.traced;
            const shown = traced !== null ? [...traced.relations] : this.routes === 'all' ? this.relations.map((relation, index) => index) : [];
            const index = this.rowIndex;
            const byId = this.graph.byId;
            const height = this.rowHeight;
            const half = this.barHeight / 2;
            let markup = '';

            for (const at of shown) {
                const relation = this.relations[at];
                const from = byId[relation.from];
                const to = byId[relation.to];

                if (!from || !to || index[from.id] === undefined || index[to.id] === undefined || from.startDay === null || to.startDay === null) {
                    continue;
                }

                const y1 = index[from.id] * height + height / 2;
                const y2 = index[to.id] * height + height / 2;
                const direction = y2 > y1 ? 1 : -1;

                if (relation.type === 'funds') {
                    const x1 = this.x(from.startDay) + Math.min(14, ((from.endDay - from.startDay + 1) * this.ppd) / 2);
                    const x2 = to.diamond ? this.x(to.startDay) + this.ppd / 2 - 9 : this.x(to.startDay) - 3;
                    const startY = y1 + direction * half;

                    markup += `<path d="${routePath(x1, startY, x2, y2)}" fill="none" stroke-width="2" stroke-linecap="round" class="stroke-funding" marker-end="url(#gantt-arrow-funding-${markerScope})"></path>`
                        + `<circle cx="${x1}" cy="${startY}" r="3" class="fill-funding"></circle>`;
                } else {
                    const x = this.x(from.startDay) + this.ppd / 2;

                    markup += `<path d="M${x},${y1 + direction * 8} V${y2}" fill="none" stroke-width="1.5" stroke-dasharray="2 3" stroke-linecap="round" class="stroke-funding"></path>`
                        + `<circle cx="${x}" cy="${y2}" r="3.5" stroke-width="1.5" class="fill-funding stroke-white dark:stroke-surface-1"></circle>`;
                }
            }

            return markup;
        },

        onLinkClick(event) {
            const index = event.target?.dataset?.linkIndex;

            if (index !== undefined) {
                this.openLinkMenu(this.drawnLinks[Number(index)], event);
            }
        },

        /* Folding, zoom, scrolling ---------------------------------------- */

        toggle(row) {
            if (!row.hasChildren) {
                return;
            }

            this.collapsed = { ...this.collapsed, [row.id]: !this.collapsed[row.id] };
            this.outlineLevel = null;
            this.persist();
        },

        expandAll() {
            this.collapsed = {};
            this.outlineLevel = 0;
            this.persist();
        },

        collapseAll() {
            this.outline(2);
        },

        /** Show the plan down to an outline level, as MS Project's Outline menu does (0 = everything). */
        outline(level) {
            if (level === 0) {
                this.expandAll();

                return;
            }

            this.collapsed = Object.fromEntries(this.rows.filter((row) => row.hasChildren && row.depth >= level - 1).map((row) => [row.id, true]));
            this.outlineLevel = level;
            this.persist();
        },

        get maxDepth() {
            return this.rows.reduce((deepest, row) => Math.max(deepest, row.depth), 0);
        },

        setZoom(level) {
            const scroller = this.$refs.scroller;
            const centreDay = scroller ? this.fromDay + (scroller.scrollLeft + (scroller.clientWidth - this.gridWidth) / 2) / this.ppd : this.todayDay;

            this.zoom = level;
            this.$nextTick(() => this.scrollToDay(centreDay, false, true));
        },

        zoomBy(direction) {
            const levels = Object.keys(ZOOM_LEVELS);
            const next = levels[Math.min(levels.length - 1, Math.max(0, levels.indexOf(this.zoom) + direction))];

            if (next !== this.zoom) {
                this.setZoom(next);
            }
        },

        /** Pick the zoom that fits the whole plan into the visible timeline. */
        zoomToFit() {
            const scroller = this.$refs.scroller;
            const dated = this.rows.filter((row) => row.startDay !== null);

            if (!scroller || dated.length === 0) {
                return;
            }

            const first = Math.min(...dated.map((row) => row.startDay));
            const last = Math.max(...dated.map((row) => row.endDay));
            const available = scroller.clientWidth - this.gridWidth - 24;
            const fitting = Object.entries(ZOOM_LEVELS).find(([, level]) => (last - first + 1) * level.ppd <= available);

            this.zoom = fitting ? fitting[0] : 'year';
            this.$nextTick(() => this.scrollToDay(first - 2, true));
        },

        onWheel(event) {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }

            event.preventDefault();
            this.zoomBy(event.deltaY > 0 ? 1 : -1);
        },

        scrollToDay(dayNumber, smooth = true, centre = false) {
            const scroller = this.$refs.scroller;

            if (!scroller) {
                return;
            }

            const offset = centre ? (scroller.clientWidth - this.gridWidth) / 2 : 0;

            scroller.scrollTo({ left: Math.max(0, this.x(dayNumber) - offset), behavior: smooth ? 'smooth' : 'auto' });
        },

        goToToday() {
            this.scrollToDay(this.todayDay, true, true);
        },

        focusRow(row) {
            this.selectedId = row.id;

            if (row.startDay !== null) {
                this.scrollToDay(row.startDay - 3);
            }
        },

        open(row) {
            if (row.url) {
                window.Livewire?.navigate ? window.Livewire.navigate(row.url) : (window.location.href = row.url);
            }
        },

        onKeydown(event) {
            const modifier = event.ctrlKey || event.metaKey;
            const key = event.key.toLowerCase();

            if (modifier && (key === 'z' || key === 'y')) {
                event.preventDefault();
                key === 'y' || event.shiftKey ? this.redo() : this.undo();

                return;
            }

            if (event.key === 'Tab' && this.editable && this.selectedId) {
                event.preventDefault();
                event.shiftKey ? this.outdent() : this.indent();

                return;
            }

            if (event.key === 'F2' && this.selectedRow()) {
                event.preventDefault();
                this.startEdit(this.selectedRow(), 'name');

                return;
            }

            if (event.key === 'Escape' && this.linkMenu !== null) {
                // Closing the link editor is all this Escape does.
                event.stopPropagation();
                this.linkMenu = null;

                return;
            }

            const rows = this.visibleRows;
            const index = rows.findIndex((row) => row.id === this.selectedId);
            const current = rows[index];

            switch (event.key) {
                case 'ArrowDown':
                case 'ArrowUp': {
                    event.preventDefault();
                    const next = rows[Math.min(rows.length - 1, Math.max(0, index + (event.key === 'ArrowDown' ? 1 : -1)))];

                    if (next) {
                        this.selectedId = next.id;
                        this.ensureVisible(rows.indexOf(next));
                    }
                    break;
                }
                case 'ArrowRight':
                case 'ArrowLeft':
                    if (current?.hasChildren) {
                        event.preventDefault();
                        this.collapsed = { ...this.collapsed, [current.id]: event.key === 'ArrowLeft' };
                        this.outlineLevel = null;
                        this.persist();
                    }
                    break;
                case 'Enter':
                    if (current) {
                        this.open(current);
                    }
                    break;
                case ' ':
                    if (current) {
                        event.preventDefault();
                        this.focusRow(current);
                    }
                    break;
            }
        },

        /** One-key shortcuts anywhere in the chart, except while typing. */
        onRootKeydown(event) {
            if (event.defaultPrevented || isTyping(event.target) || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const levels = Object.keys(ZOOM_LEVELS);

            switch (event.key) {
                case 'Escape':
                    if (this.shortcutsOpen) {
                        this.shortcutsOpen = false;
                    } else if (this.linkMenu !== null) {
                        this.linkMenu = null;
                    } else if (this.explorer) {
                        this.clearSelection();
                    }
                    break;
                case '/':
                    event.preventDefault();
                    this.$refs.search?.focus();
                    break;
                case '?':
                    this.shortcutsOpen = !this.shortcutsOpen;
                    break;
                case 't':
                case 'T':
                    this.goToToday();
                    break;
                case 'f':
                case 'F':
                    this.zoomToFit();
                    break;
                case '+':
                case '=':
                    this.zoomBy(-1);
                    break;
                case '-':
                    this.zoomBy(1);
                    break;
                case 'l':
                case 'L':
                    this.showLinks = !this.showLinks;
                    break;
                case 'c':
                case 'C':
                    this.showCritical = !this.showCritical;
                    break;
                case 'r':
                case 'R':
                    if (this.explorer) {
                        this.trace = !this.trace;
                    }
                    break;
                default:
                    if (/^[1-5]$/.test(event.key)) {
                        this.setZoom(levels[Number(event.key) - 1]);
                    }
            }
        },

        /** "/" jumps to the chart search from anywhere on the page that is not a field. */
        onWindowKeydown(event) {
            if (event.key === '/' && this.explorer && document.activeElement === document.body) {
                event.preventDefault();
                this.$refs.search?.focus();
            }
        },

        revealSelected() {
            const index = this.selectedId === null ? undefined : this.rowIndex[this.selectedId];

            if (index !== undefined) {
                this.ensureVisible(index, true);
            }
        },

        ensureVisible(index, centre = false) {
            const scroller = this.$refs.scroller;
            const height = this.rowHeight;
            const top = index * height;
            const outside = top < scroller.scrollTop || top + height > scroller.scrollTop + scroller.clientHeight - HEADER_HEIGHT;

            if (centre && outside) {
                scroller.scrollTop = Math.max(0, top - (scroller.clientHeight - HEADER_HEIGHT) / 2);
            } else if (top < scroller.scrollTop) {
                scroller.scrollTop = top;
            } else if (top + height > scroller.scrollTop + scroller.clientHeight - HEADER_HEIGHT) {
                scroller.scrollTop = top + height - scroller.clientHeight + HEADER_HEIGHT;
            }
        },

        startResize(event) {
            // A width chosen by hand is kept once the table widens again.
            this.fullGridWidth = null;

            const startX = event.clientX;
            const startWidth = this.gridWidth;
            const move = (moveEvent) => {
                this.gridWidth = Math.min(1100, Math.max(200, startWidth + (moveEvent.clientX - startX)));
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.persist();
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        /* Server round trips & undo --------------------------------------- */

        /** Call server actions one after another; the chart redraws from the last answer. */
        async run(actions) {
            let result = null;

            for (const action of actions) {
                result = await this.$wire.call(action.method, ...action.args);

                if (!result?.ok) {
                    break;
                }
            }

            if (result?.payload) {
                this.load(result.payload);
            }

            return result;
        },

        /**
         * Run one edit and record it. makeInverse(result) returns the actions
         * that undo it, built from what the server says it changed.
         */
        async apply(action, makeInverse = () => [], history = 'new') {
            if (this.busy) {
                return null;
            }

            this.busy = true;

            try {
                const result = await this.run([action]);

                if (result?.ok) {
                    this.undoStack = [...this.undoStack, { action, makeInverse, inverse: makeInverse(result) }].slice(-50);

                    if (history === 'new') {
                        this.redoStack = [];
                    }
                }

                return result;
            } finally {
                this.busy = false;
            }
        },

        async undo() {
            if (this.busy || this.undoStack.length === 0) {
                return;
            }

            const entry = this.undoStack.pop();
            this.busy = true;

            try {
                const result = await this.run(entry.inverse);

                if (result?.ok) {
                    this.redoStack.push(entry);
                }
            } finally {
                this.busy = false;
            }
        },

        async redo() {
            if (this.busy || this.redoStack.length === 0) {
                return;
            }

            const entry = this.redoStack.pop();

            await this.apply(entry.action, entry.makeInverse, 'redone');
        },

        reschedule(row, startDay, endDay, beforeStart, beforeEnd) {
            return this.apply({ method: 'ganttReschedule', args: [row.id, toIso(startDay), toIso(endDay)] }, (result) =>
                result.changes?.length ? restoreStep(result) : [{ method: 'ganttRestore', args: [[{ id: row.id, start: toIso(beforeStart), end: toIso(beforeEnd) }]] }],
            );
        },

        /* Dragging bars ------------------------------------------------ */

        canDrag(row) {
            return this.editable && row.editable && !row.summary && row.startDay !== null;
        },

        beginDrag(event, row, mode) {
            if (!this.canDrag(row) || this.busy || event.button !== 0) {
                return;
            }

            if (mode === 'progress' && !row.progressEditable) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.selectedId = row.id;
            this.linkMenu = null;

            const bar = event.currentTarget.closest('[data-bar]') ?? event.currentTarget;

            this.drag = {
                row,
                mode,
                originX: event.clientX,
                moved: false,
                barWidth: bar.getBoundingClientRect().width,
                before: { start: row.startDay, end: row.endDay, progress: row.progress },
            };

            const move = (moveEvent) => this.dragMove(moveEvent);
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.endDrag();
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        dragMove(event) {
            const drag = this.drag;

            if (!drag) {
                return;
            }

            const dx = event.clientX - drag.originX;

            if (!drag.moved && Math.abs(dx) < 3) {
                return;
            }

            drag.moved = true;
            const row = drag.row;
            const days = Math.round(dx / this.ppd);
            const { start, end, progress } = drag.before;

            switch (drag.mode) {
                case 'move':
                    row.startDay = start + days;
                    row.endDay = end + days;
                    break;
                case 'start':
                    row.startDay = Math.min(end, start + days);
                    break;
                case 'end':
                    row.endDay = Math.max(start, end + days);
                    break;
                case 'progress': {
                    const percent = (((progress / 100) * drag.barWidth + dx) / drag.barWidth) * 100;

                    row.progress = Math.min(100, Math.max(0, Math.round(percent / 5) * 5));
                    break;
                }
            }

            this.hover = { row, x: event.clientX, y: event.clientY };
        },

        async endDrag() {
            const drag = this.drag;

            this.drag = null;
            this.hideTip();

            if (!drag?.moved) {
                return;
            }

            const row = drag.row;

            if (drag.mode === 'progress') {
                if (row.progress !== drag.before.progress) {
                    await this.apply({ method: 'ganttSetProgress', args: [row.id, row.progress] }, () => [
                        { method: 'ganttSetProgress', args: [row.id, drag.before.progress] },
                    ]);
                }

                return;
            }

            if (row.startDay !== drag.before.start || row.endDay !== drag.before.end) {
                await this.reschedule(row, row.startDay, row.endDay, drag.before.start, drag.before.end);
            }
        },

        /* Drawing links bar-to-bar ----------------------------------------- */

        canLink(row) {
            return this.editable && row.editable && row.startDay !== null && !this.busy;
        },

        chartPoint(event) {
            const layer = this.$refs.linkLayer.getBoundingClientRect();

            return { x: event.clientX - layer.left, y: event.clientY - layer.top };
        },

        beginLink(event, row, side) {
            if (!this.canLink(row) || event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.linkMenu = null;

            const index = this.rowIndex[row.id];
            const height = this.rowHeight;
            const point = this.chartPoint(event);

            this.linkDraft = {
                from: row,
                side,
                x1: this.edgeX(row, side),
                y1: index * height + height / 2,
                x2: point.x,
                y2: point.y,
                target: null,
            };

            const move = (moveEvent) => {
                const moved = this.chartPoint(moveEvent);
                const over = document.elementFromPoint(moveEvent.clientX, moveEvent.clientY)?.closest('[data-row-id]');
                const target = over ? this.rows.find((candidate) => candidate.id === over.dataset.rowId) : null;

                this.linkDraft = { ...this.linkDraft, x2: moved.x, y2: moved.y, target: target && target.id !== row.id && this.canLink(target) ? target : null };
            };
            const up = async (upEvent) => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);

                const draft = this.linkDraft;

                this.linkDraft = null;

                if (!draft?.target) {
                    return;
                }

                // Dropping on the target's first half ties to its start, on the second half to its finish.
                const dropped = this.chartPoint(upEvent);
                const targetMiddle = (this.edgeX(draft.target, 'start') + this.edgeX(draft.target, 'end')) / 2;
                const type = (side === 'end' ? 'f' : 's') + (dropped.x <= targetMiddle || draft.target.diamond ? 's' : 'f');

                await this.createLink(row.id, draft.target.id, type, 0);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        createLink(fromId, toId, type, lag) {
            return this.apply({ method: 'ganttLink', args: [fromId, toId, type, lag] }, (result) => [
                result.previous
                    ? { method: 'ganttLink', args: [fromId, toId, result.previous.type, result.previous.lag] }
                    : { method: 'ganttUnlink', args: [fromId, toId] },
                ...restoreStep(result),
            ]);
        },

        openLinkMenu(link, event) {
            if (!this.editable) {
                return;
            }

            event.stopPropagation();
            this.linkMenu = { link, type: link.type, lag: link.lag, x: event.clientX, y: event.clientY };
        },

        async saveLinkMenu() {
            const menu = this.linkMenu;

            this.linkMenu = null;

            if (!menu || (menu.type === menu.link.type && Number(menu.lag) === menu.link.lag)) {
                return;
            }

            await this.createLink(menu.link.from, menu.link.to, menu.type, Math.round(Number(menu.lag) || 0));
        },

        async deleteLinkMenu() {
            const menu = this.linkMenu;

            this.linkMenu = null;

            if (!menu) {
                return;
            }

            const { from, to } = menu.link;

            await this.apply({ method: 'ganttUnlink', args: [from, to] }, (result) => [
                { method: 'ganttLink', args: [from, to, result.previous.type, result.previous.lag] },
            ]);
        },

        linkMenuStyle() {
            if (!this.linkMenu) {
                return '';
            }

            return `left:${Math.min(this.linkMenu.x, window.innerWidth - 272)}px;top:${Math.min(this.linkMenu.y + 8, window.innerHeight - 220)}px`;
        },

        rowName(rowId) {
            return this.graph.byId[rowId]?.name ?? '';
        },

        /* Working week, baselines ------------------------------------------ */

        async changeWeekend(preset) {
            if (!this.editable) {
                this.weekendPreset = preset;
                this.persist();

                return;
            }

            const before = this.weekendPreset;

            this.weekendPreset = preset;

            const result = await this.apply({ method: 'ganttSetWeekend', args: [preset] }, (answer) => [
                { method: 'ganttSetWeekend', args: [answer.previous] },
                ...restoreStep(answer),
            ]);

            if (!result?.ok) {
                this.weekendPreset = before;
            }
        },

        async saveBaseline() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await this.run([{ method: 'ganttSaveBaseline', args: [this.baselineName] }]);
                this.baselineName = '';
            } finally {
                this.busy = false;
            }
        },

        async showBaseline(id) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await this.run([{ method: 'ganttShowBaseline', args: [id === '' || id === null ? null : Number(id)] }]);
            } finally {
                this.busy = false;
            }
        },

        async deleteBaseline() {
            if (this.busy || this.baselineId === null || !window.confirm(this.labels.confirmDeleteBaseline ?? 'Delete?')) {
                return;
            }

            this.busy = true;

            try {
                await this.run([{ method: 'ganttDeleteBaseline', args: [this.baselineId] }]);
            } finally {
                this.busy = false;
            }
        },

        /* Inline cell editing ------------------------------------------ */

        canEditCell(row, field) {
            if (!this.editable || !row.editable || this.busy) {
                return false;
            }

            if (field === 'progress') {
                return row.progressEditable;
            }

            if (field === 'start' || field === 'end') {
                return !row.summary && row.startDay !== null;
            }

            return true;
        },

        startEdit(row, field) {
            if (!this.canEditCell(row, field)) {
                return;
            }

            const values = {
                name: row.name,
                start: toIso(row.startDay),
                end: toIso(row.endDay),
                progress: row.progress,
            };

            this.selectedId = row.id;
            this.editing = { rowId: row.id, field, value: values[field] };
            this.$nextTick(() => {
                const input = this.$root.querySelector('[data-gantt-editor]');

                input?.focus();
                input?.select?.();
            });
        },

        isEditing(row, field) {
            return this.editing?.rowId === row.id && this.editing?.field === field;
        },

        cancelEdit() {
            this.editing = null;
            this.$refs.scroller?.focus();
        },

        async commitEdit() {
            const editing = this.editing;

            if (!editing) {
                return;
            }

            this.editing = null;
            this.$refs.scroller?.focus();

            const row = this.rows.find((candidate) => candidate.id === editing.rowId);

            if (!row) {
                return;
            }

            if (editing.field === 'name') {
                const name = String(editing.value).trim();
                const before = row.name;

                if (name !== '' && name !== before) {
                    await this.apply({ method: 'ganttRename', args: [row.id, name] }, () => [{ method: 'ganttRename', args: [row.id, before] }]);
                }

                return;
            }

            if (editing.field === 'progress') {
                const progress = Math.min(100, Math.max(0, Math.round(Number(editing.value))));
                const before = row.progress;

                if (!Number.isNaN(progress) && progress !== before) {
                    await this.apply({ method: 'ganttSetProgress', args: [row.id, progress] }, () => [{ method: 'ganttSetProgress', args: [row.id, before] }]);
                }

                return;
            }

            const day = toDay(editing.value);

            if (day === null) {
                return;
            }

            // Changing the start keeps the duration, as MS Project does; changing the finish stretches the bar.
            const start = editing.field === 'start' ? day : row.startDay;
            const end = editing.field === 'start' ? day + (row.endDay - row.startDay) : Math.max(row.startDay, day);

            if (start !== row.startDay || end !== row.endDay) {
                await this.reschedule(row, start, end, row.startDay, row.endDay);
            }
        },

        onEditorKeydown(event) {
            event.stopPropagation();

            if (event.key === 'Enter') {
                event.preventDefault();
                this.commitEdit();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.cancelEdit();
            }
        },

        /* Indent / outdent --------------------------------------------- */

        selectedRow() {
            return this.rows.find((row) => row.id === this.selectedId) ?? null;
        },

        canReparent(row) {
            return row !== null && this.editable && row.editable && !this.busy;
        },

        /** Nest the selected row under the sibling just above it (Tab). */
        async indent() {
            const row = this.selectedRow();

            if (!this.canReparent(row)) {
                return;
            }

            const above = this.rows.slice(0, this.rows.indexOf(row)).reverse();
            const sibling = above.find((candidate) => candidate.parent === row.parent && candidate.depth === row.depth);

            if (!sibling || sibling.kind !== row.kind) {
                return;
            }

            const before = this.nestingParent(row);

            await this.apply({ method: 'ganttSetParent', args: [row.id, sibling.id] }, () => [{ method: 'ganttSetParent', args: [row.id, before] }]);
        },

        /** Lift the selected row one level up (Shift+Tab). */
        async outdent() {
            const row = this.selectedRow();

            if (!this.canReparent(row)) {
                return;
            }

            const parent = this.rows.find((candidate) => candidate.id === row.parent);

            if (!parent || parent.kind !== row.kind) {
                return;
            }

            await this.apply({ method: 'ganttSetParent', args: [row.id, this.nestingParent(parent)] }, () => [
                { method: 'ganttSetParent', args: [row.id, parent.id] },
            ]);
        },

        /** The row's parent when it is the same kind (a real nesting parent), else null. */
        nestingParent(row) {
            const parent = this.rows.find((candidate) => candidate.id === row.parent);

            return parent && parent.kind === row.kind ? parent.id : null;
        },

        /* Export ---------------------------------------------------------- */

        exportCsv() {
            exportCsv(this, `${this.scope}-gantt.csv`);
        },

        printChart() {
            printChart(this);
        },

        /* Tooltip ---------------------------------------------------------- */

        showTip(row, event) {
            if (this.drag || this.linkDraft) {
                return;
            }

            this.hover = { row, x: event.clientX, y: event.clientY };
        },

        hideTip() {
            this.hover = null;
        },

        tipStyle() {
            if (!this.hover) {
                return '';
            }

            const left = Math.min(this.hover.x + 14, window.innerWidth - 316);
            const top = this.hover.y + 300 > window.innerHeight ? this.hover.y - 290 : this.hover.y + 16;

            return `left:${Math.max(8, left)}px;top:${Math.max(8, top)}px`;
        },
    }));
});
