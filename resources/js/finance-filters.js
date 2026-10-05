/**
 * The Funding pages' filters dialog (x-funding.filters) edits a draft.
 *
 * Every control in it writes to Livewire's client-side state only
 * ($wire.$set(name, value, false)), so nothing reaches the list until
 * "Show results" sends the whole draft in one round trip (applyFilters).
 * Closing the dialog any other way (X, Esc, the backdrop) puts every value
 * back as it was when the dialog opened.
 *
 * Controls declare the properties they write with data-filter-models (and
 * data-filter-defaults for their resting values); plain inputs are found by
 * their wire:model. A section marked data-filter-keep (grouping, order) is a
 * reading choice, not a filter, so "Clear all" leaves it alone.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('financeFilters', (modalName) => ({
        snapshot: {},
        applying: false,
        dialogElement: null,

        /*
         * Flux gives the dialog its own Alpine scope, so a control inside it
         * sees that scope's $root, not this one: the dialog is found once,
         * here, and kept.
         */
        init() {
            this.dialogElement = this.$root.querySelector('dialog');
            this.dialogElement?.addEventListener('close', () => this.closed());
        },

        dialog() {
            return this.dialogElement;
        },

        models({ withKept = true } = {}) {
            const dialog = this.dialog();
            const names = new Set();

            if (! dialog) {
                return [];
            }

            dialog.querySelectorAll('[data-filter-models]').forEach((element) => {
                if (! withKept && element.closest('[data-filter-keep]')) {
                    return;
                }

                JSON.parse(element.dataset.filterModels).forEach((name) => names.add(name));
            });

            dialog.querySelectorAll('[wire\\:model]').forEach((element) => {
                if (! withKept && element.closest('[data-filter-keep]')) {
                    return;
                }

                names.add(element.getAttribute('wire:model'));
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
            return this.$wire.$get(name) ?? '';
        },

        is(name, value) {
            return String(this.value(name)) === String(value);
        },

        filled(names) {
            return names.some((name) => String(this.value(name)) !== '');
        },

        onAny(pairs) {
            return pairs.some(([name, value]) => this.is(name, value));
        },

        pick(name, value, toggle = false, also = {}) {
            const clearing = toggle && this.is(name, value);

            this.$wire.$set(name, clearing ? '' : value, false);
            Object.entries(also).forEach(([other, otherValue]) => this.$wire.$set(other, clearing ? '' : otherValue, false));
        },

        clearAll() {
            const defaults = this.defaults();

            this.models({ withKept: false }).forEach((name) => this.$wire.$set(name, defaults[name] ?? '', false));
        },

        open() {
            this.snapshot = Object.fromEntries(this.models().map((name) => [name, this.value(name)]));
            this.applying = false;
            this.$flux.modal(modalName).show();
        },

        dismiss() {
            this.$flux.modal(modalName).close();
        },

        apply() {
            this.applying = true;
            this.dismiss();
            this.$wire.applyFilters();
        },

        closed() {
            if (this.applying) {
                this.applying = false;

                return;
            }

            Object.entries(this.snapshot).forEach(([name, value]) => this.$wire.$set(name, value, false));
        },
    }));
});
