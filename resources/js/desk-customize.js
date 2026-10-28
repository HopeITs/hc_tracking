/**
 * The Desk's Customize sheet (pages/desk/_customize-sheet): add, remove and
 * resize panels as a draft. Nothing is saved until "Done" sends the whole
 * arrangement in one call ($wire.saveLayout); Cancel, X, Esc and the
 * backdrop drop the draft. Reset puts the draft on the role's layout, and
 * "Done" after it (with no edit since) saves null so the Desk keeps
 * following the role's default.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('deskCustomize', (starting) => ({
        open: false,
        draft: [],
        resetting: false,

        openSheet() {
            this.draft = JSON.parse(this.$root.dataset.layout || '[]');
            this.resetting = false;
            this.open = true;
            window.hcLockPageScroll?.();
        },

        close() {
            this.open = false;
            window.hcUnlockPageScroll?.();
        },

        discard() {
            this.draft = [];
            this.close();
        },

        save() {
            const layout = this.resetting ? null : this.draft.map(({ panel, size }) => ({ panel, size }));

            this.close();
            this.$wire.saveLayout(layout);
        },

        has(panel) {
            return this.draft.some((entry) => entry.panel === panel);
        },

        position(panel) {
            return this.draft.findIndex((entry) => entry.panel === panel);
        },

        sizeOf(panel) {
            return this.draft.find((entry) => entry.panel === panel)?.size ?? '';
        },

        edit(draft) {
            this.draft = draft;
            this.resetting = false;
        },

        add(panel, size) {
            if (! this.has(panel)) {
                this.edit([...this.draft, { panel, size }]);
            }
        },

        remove(panel) {
            this.edit(this.draft.filter((entry) => entry.panel !== panel));
        },

        resize(panel, size) {
            this.edit(this.draft.map((entry) => (entry.panel === panel ? { ...entry, size } : entry)));
        },

        reset() {
            this.draft = starting.map((entry) => ({ ...entry }));
            this.resetting = true;
        },
    }));
});
