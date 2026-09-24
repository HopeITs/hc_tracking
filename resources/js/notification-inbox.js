/**
 * The /notifications page: keyboard triage and the undo bar.
 *
 * j / k move through the rows, Enter opens, e reads or unreads, s snoozes,
 * x selects, / searches, Esc clears, ? shows the shortcuts. The active row is
 * remembered by id, so a Livewire re-render never loses the reader's place.
 *
 * Every read or snooze announces `inbox-undoable` (ManagesInbox); the bar
 * offers to reverse it for a few seconds.
 */
const UndoSeconds = 6;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('notificationInbox', () => ({
        activeId: null,
        help: false,
        undo: null,
        undoTimer: null,
        stopMorphHook: null,

        init() {
            this.$root.addEventListener('focusin', (event) => {
                const row = event.target.closest('[data-notification-row]');

                if (row) {
                    this.activeId = row.dataset.id;
                    this.paint();
                }
            });

            this.stopMorphHook = window.Livewire?.hook?.('morphed', ({ component }) => {
                if (component?.id === this.$wire.$id) {
                    this.$nextTick(() => this.paint());
                }
            });
        },

        destroy() {
            window.clearTimeout(this.undoTimer);

            if (typeof this.stopMorphHook === 'function') {
                this.stopMorphHook();
            }
        },

        bellOpen() {
            return [...document.querySelectorAll('[data-notification-panel]')].some((panel) => panel.getClientRects().length > 0);
        },

        rows() {
            return [...this.$root.querySelectorAll('[data-notification-row]')];
        },

        activeRow() {
            return this.rows().find((row) => row.dataset.id === this.activeId) ?? null;
        },

        paint() {
            for (const row of this.rows()) {
                row.toggleAttribute('data-active', row.dataset.id === this.activeId);
            }
        },

        move(step) {
            const rows = this.rows();

            if (rows.length === 0) {
                return;
            }

            const current = rows.findIndex((row) => row.dataset.id === this.activeId);
            const next = current === -1 ? (step > 0 ? 0 : rows.length - 1) : Math.min(rows.length - 1, Math.max(0, current + step));
            const row = rows[next];

            this.activeId = row.dataset.id;
            this.paint();
            row.focus({ preventScroll: true });
            row.scrollIntoView({
                block: 'nearest',
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            });
        },

        onKey(event) {
            if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const target = event.target;
            const typing = target.closest?.('input:not([type=checkbox]), textarea, select, [contenteditable=""], [contenteditable=true]');

            if (typing) {
                if (event.key === 'Escape' && target.type === 'search') {
                    target.blur();
                }

                return;
            }

            if (this.bellOpen() || (this.help && event.key !== '?')) {
                return;
            }

            const row = this.activeRow();
            const press = (selector) => {
                const element = row?.querySelector(selector);

                if (element) {
                    event.preventDefault();
                    element.click();
                }
            };

            switch (event.key) {
                case 'j':
                    event.preventDefault();
                    this.move(1);
                    break;
                case 'k':
                    event.preventDefault();
                    this.move(-1);
                    break;
                case 'Enter':
                    if (row && target === row) {
                        press('[data-notification-link]');
                    }
                    break;
                case 'e':
                    press('[data-toggle-read]');
                    break;
                case 's':
                    press('[data-snooze-toggle], [data-unsnooze]');
                    break;
                case 'x':
                    press('[data-row-select]');
                    break;
                case '/':
                    event.preventDefault();
                    this.$root.querySelector('[data-inbox-search] input, input[data-inbox-search], input[type=search]')?.focus();
                    break;
                case '?':
                    event.preventDefault();
                    this.help = !this.help;
                    break;
                case 'Escape':
                    if (this.$wire.selected.length > 0) {
                        this.$wire.set('selected', []);
                    }
                    break;
            }
        },

        showUndo(detail) {
            if (!detail?.ids?.length) {
                return;
            }

            // An open bell panel offers its own undo; one bar at a time.
            if (this.bellOpen()) {
                return;
            }

            window.clearTimeout(this.undoTimer);

            // Drop the old bar first so its countdown restarts from full.
            this.undo = null;
            this.$nextTick(() => {
                this.undo = { action: detail.action, ids: detail.ids, message: detail.message, stamp: Date.now() };
                this.undoTimer = window.setTimeout(() => (this.undo = null), UndoSeconds * 1000);
            });
        },

        undoNow() {
            if (!this.undo) {
                return;
            }

            this.$wire.undoInbox(this.undo.action, this.undo.ids);
            this.dismissUndo();
        },

        dismissUndo() {
            window.clearTimeout(this.undoTimer);
            this.undo = null;
        },
    }));
});
