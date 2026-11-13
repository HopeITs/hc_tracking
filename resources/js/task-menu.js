/**
 * Shared state for the task list's status and priority menus.
 *
 * Every row used to carry two flux:dropdowns, each with its own menu items
 * (7 per row, ~0.3 ms apiece to render, and again for every subtask and
 * "add task" draft row). Rows now only hold a trigger; one panel rendered once
 * per page (_task-option-menu.blade.php) is opened by whichever trigger was
 * clicked, mirroring the assignee popover (assignee-picker.js).
 *
 * A trigger names the Livewire method to call and its leading arguments; the
 * picked value is appended. Drafts use '$set' with the property path, so
 * `$wire.$set(path, value)` runs exactly as the old wire:click="$set(...)" did.
 */

document.addEventListener('alpine:init', () => {
    window.Alpine.store('taskMenu', {
        open: false,
        kind: null,
        key: null,
        current: '',
        method: '',
        args: [],
        top: 0,
        left: 0,
        // Right-to-left pages anchor the popover to the trigger's right edge.
        right: null,
        // The button that opened the menu, so focus can go back to it.
        trigger: null,

        openFor(kind, key, current, rect, method, args = []) {
            if (this.open && this.key === key) {
                this.close(true);

                return;
            }

            this.trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            this.kind = kind;
            this.key = key;
            this.current = String(current);
            this.method = method;
            this.args = [...args];
            this.top = rect.bottom + 4;
            this.left = rect.left;
            this.right = document.documentElement.dir === 'rtl' ? window.innerWidth - rect.right : null;

            if (!this.open) {
                window.hcLockPageScroll?.();
            }

            this.open = true;

            // A menu takes the focus, on the picked option, so the keyboard can choose too.
            window.Alpine.nextTick(() => {
                const items = this.items();

                (items.find((item) => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus();
            });
        },

        close(returnFocus = false) {
            if (this.open) {
                window.hcUnlockPageScroll?.();
            }

            this.open = false;
            this.key = null;

            if (returnFocus && this.trigger?.isConnected) {
                this.trigger.focus();
            }
        },

        pick($wire, value) {
            const { method, args, trigger } = this;
            const triggerTest = trigger?.dataset.test;

            this.close(true);

            // A card that changes status is drawn again in its new column: find its button anew.
            Promise.resolve($wire[method](...args, value)).then(() => {
                if (triggerTest && !trigger.isConnected && document.activeElement === document.body) {
                    document.querySelector(`[data-test="${triggerTest}"]`)?.focus();
                }
            });
        },

        /** The options of the open kind (status or priority), in order. */
        items() {
            const menu = document.querySelector('[data-test="task-option-menu"]');

            return menu ? [...menu.querySelectorAll('[role="menuitemradio"]')].filter((item) => item.getClientRects().length > 0) : [];
        },

        /** Arrows, Home and End move between the options; Escape and Tab leave the menu. */
        onKeydown(event) {
            const items = this.items();
            const at = items.indexOf(document.activeElement);
            const moves = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: items.length - 1 };

            if (event.key in moves && items.length > 0) {
                event.preventDefault();
                items[(moves[event.key] + items.length) % items.length].focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.close(true);
            } else if (event.key === 'Tab') {
                this.close(true);
            }
        },
    });
});
