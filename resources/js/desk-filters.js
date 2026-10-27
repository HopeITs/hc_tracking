/**
 * A Desk panel (x-desk.panel): its options menu and its filter popup.
 *
 * The popup edits a draft. Every control in it writes to Livewire's
 * client-side state only ($wire.$set(name, value, false)), so nothing reaches
 * the list until "Done" sends the whole draft in one round trip
 * (applyFilters). Closing the popup any other way (X, Esc, the backdrop)
 * puts every value back as it was when it opened, and "Clear" only resets
 * the draft to the resting values, which still wait for "Done".
 *
 * Controls declare the properties they write with data-filter-models (the
 * deferred x-ui.header-filter-select does this) and their resting values
 * with data-filter-defaults.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('deskPanel', (panelId) => ({
        menu: false,
        filtering: false,
        snapshot: {},

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
            return this.$wire.$get(name) ?? '';
        },

        is(name, value) {
            return String(this.value(name)) === String(value);
        },

        pick(name, value) {
            this.$wire.$set(name, value, false);
        },

        openFilters() {
            this.menu = false;
            this.snapshot = Object.fromEntries(this.models().map((name) => [name, this.value(name)]));
            this.filtering = true;
            window.hcLockPageScroll?.();
        },

        clearDraft() {
            const defaults = this.defaults();

            this.models().forEach((name) => this.$wire.$set(name, defaults[name] ?? '', false));
        },

        closeFilters() {
            this.filtering = false;
            window.hcUnlockPageScroll?.();
        },

        discardFilters() {
            if (! this.filtering) {
                return;
            }

            Object.entries(this.snapshot).forEach(([name, value]) => this.$wire.$set(name, value, false));
            this.closeFilters();
        },

        applyFilters() {
            this.closeFilters();
            this.$wire.applyFilters();
        },
    }));
});
