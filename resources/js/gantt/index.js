import { toDay, toIso, weekday, workingDays, unitCells, topCells, WEEKEND_PRESETS, ZOOM_LEVELS } from './timescale';
import { linkPath } from './links';
import { exportCsv, printChart } from './export';

/**
 * The Gantt engine: an Alpine component that takes the plain payload the
 * server builds (App\Livewire\Concerns\BuildsProjectGantt and friends) and
 * draws an MS-Project-style split grid + timeline.
 *
 * Read-only: WBS tree, five zoom levels, working-week shading, critical path,
 * baselines, planned vs actual, search/filters, virtualized rows, export.
 * Editable (project page): drag/resize/progress, inline cells, indent/outdent,
 * dependency links drawn bar-to-bar, working week, baselines, and an undo
 * stack that reverts every server-side ripple (auto-scheduled successors too).
 *
 * Every edit is applied optimistically, then replaced by the payload the
 * server answers with, so the chart always ends on what the database holds.
 *
 * Viewer preferences (zoom, grid width, folded rows, critical toggle) stay in
 * this browser; the working week is the project's and lives on the server.
 */

const ROW_HEIGHT = 36;
const OVERSCAN = 8;
const PAD_DAYS = 14;
const STORAGE_KEY = 'hc-gantt';

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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('ganttChart', (payload, scope = 'default', editable = false, labels = {}) => ({
        editable,
        labels,
        scope,
        rowHeight: ROW_HEIGHT,
        rows: [],
        links: [],
        baselines: [],
        baselineId: null,
        collapsed: {},
        zoom: 'week',
        weekendPreset: payload.weekend ?? 'sat-sun',
        gridWidth: 640,
        search: '',
        lateOnly: false,
        criticalOnly: false,
        showCritical: true,
        showLinks: true,
        selectedId: null,
        hover: null,
        scrollTop: 0,
        viewportHeight: 480,
        printing: false,
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
            this.gridWidth = Number(saved.gridWidth) || this.gridWidth;
            this.collapsed = saved.collapsed ?? {};
            this.showCritical = saved.showCritical ?? true;
            this.showLinks = saved.showLinks ?? true;

            if (!this.editable && WEEKEND_PRESETS[saved.weekend]) {
                this.weekendPreset = saved.weekend;
            }

            this.load(payload);

            this.$watch('zoom', () => this.persist());
            this.$watch('showCritical', () => this.persist());
            this.$watch('showLinks', () => this.persist());

            this.$nextTick(() => {
                this.measure();
                this.scrollToDay(this.todayDay - 7, false);
            });
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
            this.baselines = data.baselines ?? [];
            this.baselineId = data.baselineId ?? null;

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
                gridWidth: this.gridWidth,
                collapsed: this.collapsed,
                showCritical: this.showCritical,
                showLinks: this.showLinks,
            });
        },

        measure() {
            const scroller = this.$refs.scroller;

            if (!scroller) {
                return;
            }

            this.viewportHeight = scroller.clientHeight;
            // Never let the table crowd out the timeline on a narrow screen.
            this.gridWidth = Math.min(this.gridWidth, Math.max(240, Math.round(scroller.clientWidth * 0.6)));
        },

        /* Geometry ------------------------------------------------------- */

        get ppd() {
            return ZOOM_LEVELS[this.zoom].ppd;
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

        /** Grid columns: WBS · name · start · finish · duration · % · predecessors [· variance]. */
        get gridTemplate() {
            const columns = ['2.5rem', 'minmax(9rem, 1fr)', '4.5rem', '4.5rem', '2.75rem', '2.25rem', '4.25rem'];

            if (this.hasBaseline) {
                columns.push('3rem');
            }

            return `grid-template-columns:${columns.join(' ')}`;
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

        /** Rows that survive collapse, search and the "late"/"critical" filters. */
        get visibleRows() {
            const term = this.search.trim().toLowerCase();
            const byId = Object.fromEntries(this.rows.map((row) => [row.id, row]));
            const isHidden = (row) => {
                for (let parent = byId[row.parent]; parent; parent = byId[parent.parent]) {
                    if (this.collapsed[parent.id] && !this.printing) {
                        return true;
                    }
                }

                return false;
            };

            if (term === '' && !this.lateOnly && !this.criticalOnly) {
                return this.rows.filter((row) => !isHidden(row));
            }

            // When filtering, keep every match plus its ancestors so the WBS context stays readable.
            const keep = new Set();

            for (const row of this.rows) {
                const matchesTerm = term === '' || row.name.toLowerCase().includes(term) || (row.owner ?? '').toLowerCase().includes(term);
                const matchesLate = !this.lateOnly || row.late;
                const matchesCritical = !this.criticalOnly || row.critical;

                if (matchesTerm && matchesLate && matchesCritical && row.kind !== 'group') {
                    keep.add(row.id);

                    for (let parent = byId[row.parent]; parent; parent = byId[parent.parent]) {
                        keep.add(parent.id);
                    }
                }
            }

            return this.rows.filter((row) => keep.has(row.id));
        },

        get rowIndex() {
            return Object.fromEntries(this.visibleRows.map((row, index) => [row.id, index]));
        },

        get windowed() {
            const rows = this.visibleRows;

            if (this.printing) {
                return rows.map((row, index) => ({ row, index }));
            }

            const first = Math.max(0, Math.floor(this.scrollTop / ROW_HEIGHT) - OVERSCAN);
            const last = Math.min(rows.length, Math.ceil((this.scrollTop + this.viewportHeight) / ROW_HEIGHT) + OVERSCAN);

            return rows.slice(first, last).map((row, offset) => ({ row, index: first + offset }));
        },

        get bodyHeight() {
            return this.visibleRows.length * ROW_HEIGHT;
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

        formatDay(dayNumber) {
            if (dayNumber === null || dayNumber === undefined) {
                return '—';
            }

            return new Intl.DateTimeFormat(this.locale, { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(toIso(dayNumber)));
        },

        isCriticalShown(row) {
            return this.showCritical && row.critical === true;
        },

        /* Dependency arrows ---------------------------------------------- */

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
            const byId = Object.fromEntries(this.rows.map((row) => [row.id, row]));

            return this.links
                .filter((link) => index[link.from] !== undefined && index[link.to] !== undefined && byId[link.from].startDay !== null && byId[link.to].startDay !== null)
                .map((link) => {
                    const from = byId[link.from];
                    const to = byId[link.to];
                    const fromSide = link.type[0] === 'f' ? 'end' : 'start';
                    const toSide = link.type[1] === 's' ? 'start' : 'end';

                    return {
                        ...link,
                        critical: this.showCritical && from.critical && to.critical,
                        d: linkPath(
                            this.edgeX(from, fromSide),
                            index[link.from] * ROW_HEIGHT + ROW_HEIGHT / 2,
                            this.edgeX(to, toSide),
                            index[link.to] * ROW_HEIGHT + ROW_HEIGHT / 2,
                            fromSide,
                            toSide,
                            ROW_HEIGHT,
                        ),
                    };
                });
        },

        /** SVG markup for every drawn arrow: a visible stroke plus a wide invisible hit path. */
        linksMarkup(markerScope) {
            const escape = (text) => String(text).replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);

            return this.drawnLinks
                .map((link, index) => {
                    const stroke = link.critical ? 'stroke-status-critical' : 'stroke-secondary-dark/70 dark:stroke-white/70';
                    const marker = link.critical ? `gantt-arrow-critical-${markerScope}` : `gantt-arrow-${markerScope}`;
                    const lag = link.lag ? ` ${link.lag > 0 ? '+' : ''}${link.lag}d` : '';
                    const title = `${this.rowName(link.from)} → ${this.rowName(link.to)} · ${link.label}${lag}`;
                    const hitClass = this.editable ? 'cursor-pointer' : '';

                    return `<path d="${link.d}" fill="none" stroke-width="1.5" class="${stroke}" marker-end="url(#${escape(marker)})"></path>`
                        + `<path d="${link.d}" fill="none" stroke="transparent" stroke-width="10" class="${hitClass}" style="pointer-events:stroke" data-link-index="${index}"><title>${escape(title)}</title></path>`;
                })
                .join('');
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
            this.persist();
        },

        expandAll() {
            this.collapsed = {};
            this.persist();
        },

        collapseAll() {
            this.collapsed = Object.fromEntries(this.rows.filter((row) => row.hasChildren && row.depth > 0).map((row) => [row.id, true]));
            this.persist();
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

            if (event.key === 'Escape') {
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

        ensureVisible(index) {
            const scroller = this.$refs.scroller;
            const top = index * ROW_HEIGHT;
            const headerHeight = 56;

            if (top < scroller.scrollTop) {
                scroller.scrollTop = top;
            } else if (top + ROW_HEIGHT > scroller.scrollTop + scroller.clientHeight - headerHeight) {
                scroller.scrollTop = top + ROW_HEIGHT - scroller.clientHeight + headerHeight;
            }
        },

        startResize(event) {
            const startX = event.clientX;
            const startWidth = this.gridWidth;
            const move = (moveEvent) => {
                this.gridWidth = Math.min(1100, Math.max(240, startWidth + (moveEvent.clientX - startX)));
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

            this.linkDraft = {
                from: row,
                side,
                x1: this.edgeX(row, side),
                y1: index * ROW_HEIGHT + ROW_HEIGHT / 2,
                ...(() => {
                    const point = this.chartPoint(event);

                    return { x2: point.x, y2: point.y };
                })(),
                target: null,
            };

            const move = (moveEvent) => {
                const point = this.chartPoint(moveEvent);
                const over = document.elementFromPoint(moveEvent.clientX, moveEvent.clientY)?.closest('[data-row-id]');
                const target = over ? this.rows.find((candidate) => candidate.id === over.dataset.rowId) : null;

                this.linkDraft = { ...this.linkDraft, x2: point.x, y2: point.y, target: target && target.id !== row.id && this.canLink(target) ? target : null };
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
                const point = this.chartPoint(upEvent);
                const targetMiddle = (this.edgeX(draft.target, 'start') + this.edgeX(draft.target, 'end')) / 2;
                const type = (side === 'end' ? 'f' : 's') + (point.x <= targetMiddle || draft.target.diamond ? 's' : 'f');

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
            return this.rows.find((row) => row.id === rowId)?.name ?? '';
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

            const left = Math.min(this.hover.x + 14, window.innerWidth - 300);

            return `left:${left}px;top:${this.hover.y + 16}px`;
        },
    }));
});
