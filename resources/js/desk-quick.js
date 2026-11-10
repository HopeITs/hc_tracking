/**
 * Quick access on My desk (pages/desk/_quick-access): the button beside the
 * Needs you figures and the list that unrolls from it (desk-workspace.css).
 *
 * Keys, while it is open: 1–9 open that row, ↑ ↓ move between rows (and
 * wrap), Home / End jump to the first / last, Esc closes and gives focus back
 * to the button. ↓ on the closed button opens it on the first row. A row
 * opens through its own link, so the Desk tabs (desk-tabs.js) take it as any
 * other link on the Desk.
 *
 * Rows reorder by dragging: the row under the pointer moves in the list as
 * it is carried, and letting go saves the new order ($wire.sortQuickAccess).
 * Adding comes from the Ask bar (desk-drop.js); removing is each row's own ×.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcQuickAccess', () => ({
        open: false,
        sorting: null,
        startOrder: '',

        items() {
            return [...(this.$refs.list?.querySelectorAll('[data-quick-item]') ?? [])];
        },

        toggle() {
            this.open ? this.close(true) : this.show(false);
        },

        show(focusFirst) {
            this.open = true;

            if (focusFirst) {
                this.$nextTick(() => this.items()[0]?.focus());
            }
        },

        close(giveFocusBack) {
            if (! this.open) {
                return;
            }

            this.open = false;

            if (giveFocusBack) {
                this.$refs.toggle?.focus();
            }
        },

        onKey(event) {
            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (! this.open) {
                if (event.key === 'ArrowDown' && event.target === this.$refs.toggle) {
                    event.preventDefault();
                    this.show(true);
                }

                return;
            }

            const items = this.items();
            const at = items.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
            } else if (/^[1-9]$/.test(event.key) && items[Number(event.key) - 1]) {
                event.preventDefault();
                items[Number(event.key) - 1].click();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                items[(at + step + items.length) % items.length]?.focus();
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
            } else if (event.key === 'Tab') {
                this.close(false);
            }
        },

        keys() {
            return [...(this.$refs.list?.querySelectorAll('[data-quick-key]') ?? [])].map((row) => row.dataset.quickKey);
        },

        startSort(event) {
            const row = event.target.closest('[data-quick-key]');

            if (! row || ! event.dataTransfer) {
                return;
            }

            this.sorting = row;
            this.startOrder = this.keys().join('|');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', row.dataset.quickKey);
            requestAnimationFrame(() => row.classList.add('dk-quick-moving'));
        },

        /** The carried row takes the place of the one under the pointer: above it in its top half, below it in its bottom half. */
        overSort(event) {
            const row = event.target.closest('[data-quick-key]');

            if (! this.sorting) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            if (! row || row === this.sorting) {
                return;
            }

            const box = row.getBoundingClientRect();
            const before = event.clientY < box.top + box.height / 2;

            row.parentElement.insertBefore(this.sorting, before ? row : row.nextElementSibling);
        },

        /** Dropped in the list or let go anywhere: the order the rows now stand in is saved, if it changed. */
        endSort() {
            if (! this.sorting) {
                return;
            }

            this.sorting.classList.remove('dk-quick-moving');
            this.sorting = null;

            const order = this.keys();

            if (order.join('|') !== this.startOrder) {
                this.$wire.sortQuickAccess(order);
            }
        },
    }));
});
