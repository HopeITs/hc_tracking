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

        openFor(kind, key, current, rect, method, args = []) {
            if (this.open && this.key === key) {
                this.close();

                return;
            }

            this.kind = kind;
            this.key = key;
            this.current = String(current);
            this.method = method;
            this.args = [...args];
            this.top = rect.bottom + 4;
            this.left = rect.left;

            if (!this.open) {
                window.hcLockPageScroll?.();
            }

            this.open = true;
        },

        close() {
            if (this.open) {
                window.hcUnlockPageScroll?.();
            }

            this.open = false;
            this.key = null;
        },

        pick($wire, value) {
            const { method, args } = this;

            this.close();
            $wire[method](...args, value);
        },
    });
});
