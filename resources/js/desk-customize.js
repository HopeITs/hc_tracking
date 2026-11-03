/**
 * The Desk's Customize sheet (pages/desk/_customize-sheet): choose a ready
 * layout or add, remove and reorder panels, as a draft shown live on the map.
 * Nothing is saved until "Done" sends the whole arrangement in one call
 * ($wire.saveLayout); Cancel, X, Esc and the backdrop drop the draft, and Done
 * on an unchanged draft only closes. Reset puts the draft on the role's layout,
 * and "Done" after it (with no edit since) saves null so the Desk keeps
 * following the role's default.
 *
 * Looking at a ready layout only shows it on the stage; the footer's "Use" or
 * Adjust panels loads it into the draft, and an edit after that clears the choice. Each choice's live signal and the suggestion come from
 * $wire.readySignals when the sheet opens, never with the page; a layout with
 * no signal has nothing for this viewer and leaves the sheet. "Done" on an
 * unedited choice tells the Desk (dk-layout-applied) what to offer as Undo,
 * once the save went through.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('deskCustomize', (starting, ready = [], favourite = null, panels = {}, texts = {}) => ({
        open: false,
        pane: 'desk',
        draft: [],
        saved: [],
        resetting: false,
        selected: null,
        viewing: null,
        browsed: false,
        resizing: null,
        search: '',
        signals: {},
        signalsAt: 0,
        signalsRead: false,
        signalsFailed: false,
        suggested: favourite,
        suggestionReason: null,

        openSheet(requestedPane = 'desk') {
            this.saved = JSON.parse(this.$root.dataset.layout || '[]');
            this.draft = this.saved.map((entry) => ({ ...entry }));
            this.resetting = false;
            this.selected = null;
            this.search = '';
            this.browsed = false;
            this.viewing = this.suggested ?? ready[0]?.key ?? null;
            this.pane = requestedPane === 'layouts' && ready.length > 0 ? 'layouts' : 'desk';
            this.open = true;
            window.hcLockPageScroll?.();
            this.loadSignals();
        },

        close() {
            this.open = false;
            window.hcUnlockPageScroll?.();
        },

        discard() {
            this.draft = [];
            this.selected = null;
            this.close();
        },

        async save() {
            if (this.isUnchanged()) {
                this.discard();

                return;
            }

            const layout = this.resetting ? null : this.layoutToSave(this.draft);
            const choice = ready.find((entry) => entry.key === this.selected);
            const previous = this.$root.dataset.layoutSaved === 'true' ? this.layoutToSave(this.saved) : null;

            this.close();

            /* The toast confirms a save that happened, so it waits for the server. */
            try {
                await this.$wire.saveLayout(layout);
            } catch {
                return;
            }

            if (choice) {
                this.$root.dispatchEvent(new CustomEvent('dk-layout-applied', { bubbles: true, detail: { title: choice.title, previous } }));
            }
        },

        layoutToSave(entries) {
            return entries.map(({ panel, size, cols, rows, col }) => ({ panel, size, cols, rows, col }));
        },

        /* A reset of a desk already on the role's layout changes nothing; of a saved desk it clears the saved one, even when the panels match. */
        isUnchanged() {
            if (this.resetting && this.$root.dataset.layoutSaved === 'true') {
                return false;
            }

            return JSON.stringify(this.layoutToSave(this.draft)) === JSON.stringify(this.layoutToSave(this.saved));
        },

        /* Asked once a minute at most: reopening the sheet right away keeps what it has. */
        async loadSignals() {
            if (Date.now() - this.signalsAt < 60000) {
                return;
            }

            this.signalsAt = Date.now();
            this.signalsFailed = false;

            try {
                const { signals, suggestion } = await this.$wire.readySignals();

                this.signals = signals;
                this.signalsRead = true;
                this.suggested = suggestion.key ?? this.suggested;
                this.suggestionReason = suggestion.reason;

                if (! this.browsed) {
                    this.viewing = this.suggested;
                }
            } catch {
                this.signalsAt = 0;
                this.signalsFailed = true;
            }
        },

        /* Looking at a layout changes nothing; only the footer's button or Adjust panels loads it. */
        view(key) {
            this.viewing = key;
            this.browsed = true;
        },

        viewed() {
            return ready.find((entry) => entry.key === this.viewing) ?? null;
        },

        step(by) {
            const rows = [...this.$root.ownerDocument.querySelectorAll('[data-ready-layout]')].filter((row) => row.offsetParent !== null);
            const next = rows[rows.findIndex((row) => row.dataset.readyLayout === this.viewing) + by];

            if (next) {
                this.view(next.dataset.readyLayout);
                next.focus();
            }
        },

        eyebrow() {
            if (this.viewing === this.suggested) {
                return this.suggestionReason ?? texts.suggested;
            }

            return this.viewing && this.isCurrent(this.viewing) ? texts.current : '';
        },

        choose(key) {
            const choice = ready.find((entry) => entry.key === key);

            if (! choice) {
                return;
            }

            this.draft = choice.entries.map((entry) => ({ ...entry }));
            this.resetting = false;
            this.selected = key;
        },

        adjust() {
            this.choose(this.viewing);
            this.pane = 'desk';
        },

        /* On a layout not yet on the desk the button uses it; anywhere else it saves the draft. */
        usesViewed() {
            return this.pane === 'layouts' && !! this.viewed() && ! this.isCurrent(this.viewing);
        },

        confirmLabel() {
            return this.usesViewed() ? texts.use.replace('__L__', this.viewed().title) : texts.done;
        },

        confirm() {
            if (this.usesViewed()) {
                this.choose(this.viewing);
            }

            this.save();
        },

        chosen() {
            return ready.find((entry) => entry.key === this.selected) ?? null;
        },

        chosenCore() {
            return this.chosen()?.core ?? null;
        },

        /* The saved desk holds exactly this layout's panels, in any order or size. */
        isCurrent(key) {
            const layoutPanels = ready.find((entry) => entry.key === key)?.entries.map((entry) => entry.panel) ?? [];

            return layoutPanels.length === this.saved.length && this.saved.every((entry) => layoutPanels.includes(entry.panel));
        },

        has(panel) {
            return this.draft.some((entry) => entry.panel === panel);
        },

        titleOf(panel) {
            return panels[panel]?.title ?? panel;
        },

        cellStyle(entry) {
            return { '--c': entry.cols ?? panels[entry.panel]?.cols ?? 4, '--r': entry.rows ?? panels[entry.panel]?.rows ?? 4, '--col': entry.col ?? 'auto' };
        },

        edit(draft) {
            this.draft = draft;
            this.resetting = false;
            this.selected = null;
        },

        add(panel) {
            if (this.has(panel)) {
                return;
            }

            const { size, cols, rows } = panels[panel];
            const offered = this.visibleControls('[data-customize-add] button');
            const next = offered[offered.findIndex((button) => button.closest('[data-customize-add]').dataset.customizeAdd === panel) + 1];

            this.edit([...this.draft, { panel, size, cols, rows }]);
            this.$nextTick(() => (next ?? this.$refs.search).focus());
        },

        remove(panel) {
            const index = this.draft.findIndex((entry) => entry.panel === panel);
            const neighbour = this.draft[index + 1]?.panel ?? this.draft[index - 1]?.panel;

            /* The clicked cell leaves with this edit and takes its view of $refs along, so they are read first. */
            const { search } = this.$refs;
            const neighbourRemove = neighbour ? this.visibleControls(`[data-panel="${neighbour}"] [data-cz-act="remove"]`)[0] : null;

            this.edit(this.draft.filter((entry) => entry.panel !== panel));
            this.$nextTick(() => (neighbourRemove ?? search).focus());
        },

        /* A pinned column would fight the new order, so a moved card flows again. */
        move(panel, step) {
            const index = this.draft.findIndex((entry) => entry.panel === panel);
            const target = index + step;

            if (target < 0 || target >= this.draft.length) {
                return;
            }

            const draft = this.draft.map((entry) => ({ ...entry }));
            const [moved] = draft.splice(index, 1);

            delete moved.col;
            draft.splice(target, 0, moved);
            this.edit(draft);

            /* Re-inserting a node drops its focus; at either end the button is disabled, so its partner takes over. */
            const atEnd = target === 0 || target === this.draft.length - 1;
            const action = step < 0 ? (atEnd ? 'later' : 'earlier') : (atEnd ? 'earlier' : 'later');

            this.$nextTick(() => this.focusControl(panel, action));
        },

        sizeLabel(entry) {
            return `${entry.cols ?? panels[entry.panel]?.cols} × ${entry.rows ?? panels[entry.panel]?.rows}`;
        },

        /* The Desk's own resize on the preview: width in columns (3–12), height in row units (2–8), RTL-aware; Esc puts the card back. */
        startResize(event, panel, edge) {
            const cell = event.target.closest('.dk-cz-cell');
            const grid = cell.parentElement;
            const before = this.draft.map((entry) => ({ ...entry }));
            const entry = this.draft.find((candidate) => candidate.panel === panel);
            const startCols = Number(entry.cols ?? panels[panel]?.cols ?? 4);
            const startRows = Number(entry.rows ?? panels[panel]?.rows ?? 4);
            const style = getComputedStyle(grid);
            const rtl = style.direction === 'rtl';
            const gap = parseFloat(style.columnGap) || 0;
            const box = grid.getBoundingClientRect();
            const cellBox = cell.getBoundingClientRect();
            const colWidth = (box.width + gap) / 12;
            const rowHeight = (cellBox.height + gap) / startRows;
            const startColumn = Math.round((rtl ? box.right - cellBox.right : cellBox.left - box.left) / colWidth) + 1;
            const endLine = startColumn + startCols;
            const startX = event.clientX;
            const startY = event.clientY;

            this.resizing = { panel, edge };

            const onMove = (moved) => {
                const dx = (moved.clientX - startX) * (rtl ? -1 : 1);
                const dy = moved.clientY - startY;
                const widthSteps = Math.round(dx / colWidth);
                const heightSteps = Math.round(dy / rowHeight);
                let cols = startCols;
                let rows = startRows;

                if (edge === 'end' || edge === 'corner') {
                    cols = startCols + widthSteps;
                } else if (edge === 'start') {
                    cols = startCols - widthSteps;
                }

                if (edge === 'bottom' || edge === 'corner') {
                    rows = startRows + heightSteps;
                } else if (edge === 'top') {
                    rows = startRows - heightSteps;
                }

                cols = Math.max(3, Math.min(edge === 'start' ? endLine - 1 : 12, cols));
                rows = Math.max(2, Math.min(8, rows));

                this.draft = before.map((candidate) => {
                    if (candidate.panel !== panel) {
                        return candidate;
                    }

                    const { col: pinned, ...rest } = candidate;
                    const col = edge === 'start' ? endLine - cols : pinned;

                    return { ...rest, cols, rows, ...(col && col + cols <= 13 ? { col } : {}) };
                });

                const changed = cols !== startCols || rows !== startRows;

                this.resetting = changed ? false : this.resetting;
                this.selected = changed ? null : this.selected;
            };

            const stop = () => {
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', stop);
                window.removeEventListener('pointercancel', stop);
                window.removeEventListener('keydown', onKey, true);
                this.resizing = null;
            };

            const previous = { resetting: this.resetting, selected: this.selected };

            const onKey = (pressed) => {
                if (pressed.key === 'Escape') {
                    pressed.stopPropagation();
                    this.draft = before;
                    Object.assign(this, previous);
                    stop();
                }
            };

            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', stop);
            window.addEventListener('pointercancel', stop);
            window.addEventListener('keydown', onKey, true);
        },

        visibleControls(selector) {
            return [...this.$refs.body.querySelectorAll(selector)].filter((control) => control.offsetParent !== null);
        },

        focusControl(panel, action) {
            this.visibleControls(`[data-panel="${panel}"] [data-cz-act="${action}"]`)[0]?.focus();
        },

        matchesSearch(text) {
            return this.search === '' || text.includes(this.search.toLowerCase());
        },

        isAddable(panel) {
            return ! this.has(panel) && this.matchesSearch(panels[panel]?.search ?? '');
        },

        groupHasMatches(kind) {
            return Object.keys(panels).some((panel) => panels[panel].kind === kind && this.isAddable(panel));
        },

        anyMatches() {
            return Object.keys(panels).some((panel) => this.isAddable(panel));
        },

        reset() {
            this.draft = starting.map((entry) => ({ ...entry }));
            this.resetting = true;
            this.selected = null;
        },

        plural(forms, count) {
            const form = count === 1 ? 'one' : count === 2 ? 'two' : count <= 10 ? 'few' : 'many';

            return forms[form].replace('__N__', count);
        },

        /* "2 added, 1 removed" against the saved desk, or the count when no panel came or went. */
        changeSummary() {
            const added = this.draft.filter((entry) => ! this.saved.some((saved) => saved.panel === entry.panel)).length;
            const removed = this.saved.filter((saved) => ! this.has(saved.panel)).length;

            if (added + removed === 0) {
                return this.draft.length === 0 ? texts.count.zero : this.plural(texts.count, this.draft.length);
            }

            return [
                added > 0 ? this.plural(texts.added, added) : null,
                removed > 0 ? this.plural(texts.removed, removed) : null,
            ].filter(Boolean).join(texts.separator);
        },
    }));
});
