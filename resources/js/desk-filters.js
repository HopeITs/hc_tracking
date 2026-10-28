/**
 * A Desk panel (x-desk.panel): its options menu, its filter popup and the
 * fit of its rows to the card.
 *
 * Filters: the popup edits a draft kept here, in the browser. Picking a
 * value never touches Livewire, so nothing reaches the list — not even with
 * another request from the panel — until "Done" sends the whole draft in one
 * round trip ($wire.applyFilters(draft)). Closing the popup any other way
 * (X, Esc, the backdrop) drops the draft, and "Clear" only resets the draft
 * to the resting values, which still wait for "Done".
 *
 * Controls declare the properties they write with data-filter-models (the
 * deferred x-ui.header-filter-select does this) and their resting values
 * with data-filter-defaults.
 *
 * Fit: every panel has one fixed height. A list marked data-fit is measured
 * after each render: when its rows spill past the card the panel asks for
 * fewer rows a page ($wire.fitRows), when a page leaves room for more rows
 * (and more exist) it asks for more. What is left over is shared between the
 * rows (.dk-fit-fill), so a full page fills the card top to bottom.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('deskPanel', (panelId) => ({
        menu: false,
        filtering: false,
        draft: {},
        fitTries: 0,
        fitCeiling: Infinity,
        fitBox: '',
        fitBusy: false,
        scheduleFit: () => {},

        init() {
            const body = this.$root.querySelector(':scope > .dk-pb');

            if (! body) {
                return;
            }

            let queued = false;
            const schedule = () => {
                if (queued) {
                    return;
                }

                queued = true;
                requestAnimationFrame(() => {
                    queued = false;
                    this.fit(body);
                });
            };

            this.scheduleFit = schedule;
            this.observers = [new ResizeObserver(schedule), new MutationObserver(schedule)];
            this.observers[0].observe(body);
            this.observers[1].observe(body, { childList: true, subtree: true });
            document.fonts?.ready.then(schedule);
            schedule();
        },

        destroy() {
            this.observers?.forEach((observer) => observer.disconnect());
        },

        dialog() {
            return document.querySelector(`[data-panel-filter-dialog="${panelId}"]`);
        },

        models() {
            const names = new Set();

            this.dialog()?.querySelectorAll('[data-filter-models]').forEach((element) => {
                JSON.parse(element.dataset.filterModels).forEach((name) => names.add(name));
            });

            return [...names];
        },

        defaults() {
            const values = {};

            this.dialog()?.querySelectorAll('[data-filter-defaults]').forEach((element) => {
                Object.assign(values, JSON.parse(element.dataset.filterDefaults));
            });

            return values;
        },

        value(name) {
            if (Object.hasOwn(this.draft, name)) {
                return this.draft[name];
            }

            return this.$wire.$get(name) ?? '';
        },

        is(name, value) {
            return String(this.value(name)) === String(value);
        },

        pick(name, value) {
            this.draft = { ...this.draft, [name]: value };
        },

        openFilters() {
            this.menu = false;
            this.draft = {};
            this.filtering = true;
            window.hcLockPageScroll?.();
        },

        clearDraft() {
            const defaults = this.defaults();

            this.draft = Object.fromEntries(this.models().map((name) => [name, defaults[name] ?? '']));
        },

        closeFilters() {
            this.filtering = false;
            window.hcUnlockPageScroll?.();
        },

        discardFilters() {
            if (! this.filtering) {
                return;
            }

            this.draft = {};
            this.closeFilters();
        },

        applyFilters() {
            const draft = this.draft;

            this.draft = {};
            this.closeFilters();
            this.$wire.applyFilters(draft);
        },

        fit(body) {
            const list = body.querySelector('[data-fit]');

            if (! list || this.fitBusy) {
                return;
            }

            // A new width or a clearly new height is a new card: measure afresh. A pixel or two (the pager appearing) is not.
            const box = `${body.clientWidth}x${Math.round(body.clientHeight / 24)}`;

            if (box !== this.fitBox) {
                this.fitBox = box;
                this.fitTries = 0;
                this.fitCeiling = Infinity;
            }

            list.classList.remove('dk-fit-fill');

            const per = Number(list.dataset.fitPer);
            const total = Number(list.dataset.fitTotal);
            const rows = [...list.children];
            const items = rows.filter((row) => ! row.hasAttribute('data-fit-head'));
            const bodyBox = body.getBoundingClientRect();
            const room = bodyBox.bottom - parseFloat(getComputedStyle(body).paddingBottom) - list.getBoundingClientRect().top;
            const heights = rows.map((row) => row.getBoundingClientRect().height);
            const used = heights.reduce((sum, height) => sum + height, 0);

            if (items.length === 0 || room <= 0) {
                return;
            }

            let wanted = per;

            if (used > room + 1) {
                let sum = 0;
                let fits = 0;

                rows.forEach((row, at) => {
                    sum += heights[at];

                    if (sum <= room + 1 && ! row.hasAttribute('data-fit-head')) {
                        fits++;
                    }
                });

                wanted = Math.max(1, Math.min(fits, items.length - 1 || 1));
                this.fitCeiling = wanted;
            } else if (items.length === per && total > per) {
                const average = items.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0) / items.length;
                const extra = Math.floor((room - used) / Math.max(average, 1));

                if (extra >= 1) {
                    wanted = Math.min(per + extra, this.fitCeiling);
                }
            }

            if (wanted !== per && this.fitTries < 4) {
                this.fitTries++;
                this.fitBusy = true;
                this.$wire.fitRows(wanted).finally(() => {
                    this.fitBusy = false;
                    this.scheduleFit();
                });

                return;
            }

            // A full page, or one with a few rows, shares the room left between its rows; one or two stray rows keep to the top.
            list.classList.toggle('dk-fit-fill', used <= room + 1 && (items.length === per || items.length >= 3 || used >= room * 0.5));
        },
    }));
});
