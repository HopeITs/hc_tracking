/**
 * Shared state for the task list's assignee popover.
 *
 * The list used to render one full x-ui.multi-select-popover per row, each
 * looping every HrEmployee (200+ in this org) — with 20 rows on a page that
 * put 4000+ option buttons in the Livewire-rendered HTML, all of it
 * recomputed and diffed on *every* Livewire round trip on the page (not just
 * assignee clicks), which is why picking an assignee felt so much slower
 * than the priority/status dropdowns (3-4 items, not 200+).
 *
 * This store lets every row share a single popover instance instead: a row's
 * trigger just calls openFor() with its task id, current assignee ids and
 * trigger rect; the one popover panel (rendered once, see
 * _assignee-popover.blade.php) reads those back via $store.assigneePicker.
 * Toggling updates assignedIds optimistically (instant checkmark feedback)
 * before the $wire call round-trips to persist it. Those calls are
 * #[Renderless] on the component; the list re-renders once, on close.
 *
 * The board cards and every "add task" / "add subtask" draft row open this
 * same popover too, passing the Livewire method to call and its leading
 * arguments (the employee id is appended). Only togglePickedAssignee() on a saved
 * task keeps at least one assignee; a draft may be emptied.
 */

document.addEventListener('alpine:init', () => {
    window.Alpine.store('assigneePicker', {
        open: false,
        taskId: null,
        method: 'togglePickedAssignee',
        args: [],
        assignedIds: [],
        dirty: false,
        wire: null,
        optionSearch: '',
        top: 0,
        left: 0,

        openFor(taskId, assignedIds, rect, method = 'togglePickedAssignee', args = [taskId]) {
            if (this.open && this.taskId === taskId) {
                this.close();

                return;
            }

            this.flush();

            this.taskId = taskId;
            this.method = method;
            this.args = [...args];
            this.assignedIds = [...assignedIds];
            this.optionSearch = '';
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
            this.taskId = null;
            this.flush();
        },

        /**
         * The toggle actions are renderless, so the list still shows the old
         * assignees: re-render it once for the whole batch of picks.
         */
        flush() {
            if (this.dirty) {
                this.dirty = false;
                this.wire?.$refresh();
            }
        },

        isAssigned(employeeId) {
            return this.assignedIds.includes(employeeId);
        },

        toggle($wire, employeeId) {
            const index = this.assignedIds.indexOf(employeeId);

            if (index === -1) {
                this.assignedIds.push(employeeId);
            } else if (this.method !== 'togglePickedAssignee' || this.assignedIds.length > 1) {
                this.assignedIds.splice(index, 1);
            } else {
                // Mirrors toggleAssignee()'s own "keep at least one
                // assignee" guard — refuse client-side too so the check
                // mark doesn't flicker off before the server rejects it.
                return;
            }

            this.dirty = true;
            this.wire = $wire;
            $wire[this.method](...this.args, employeeId);
        },
    });
});

/**
 * The one shared assignee popover (_assignee-popover.blade.php): it draws the
 * employee list with hcEmployeeList the first time it opens, then only moves
 * check marks and filters as the store's assignees and search change.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('assigneePopover', () => ({
        init() {
            const picker = window.Alpine.store('assigneePicker');

            this.$watch(() => picker.open, (open) => open && this.paint());
            this.$watch(() => picker.assignedIds.length + ':' + picker.assignedIds.join(','), () => this.paint());
            this.$watch(() => picker.optionSearch, () => this.paint());
        },

        async paint() {
            const list = this.$refs.employeeList;
            const picker = window.Alpine.store('assigneePicker');
            const employees = window.Alpine.store('employeeOptions');

            await employees.load(this.$root.dataset.employeesUrl);

            window.hcEmployeeList.render(list, employees.items);
            window.hcEmployeeList.mark(list, (value) => picker.isAssigned(Number(value)));
            window.hcEmployeeList.filter(list, picker.optionSearch);
        },

        pickEmployee(event, $wire) {
            const button = event.target.closest('[data-option-value]');

            if (button !== null && ! button.disabled) {
                window.Alpine.store('assigneePicker').toggle($wire, Number(button.dataset.optionValue));
            }
        },
    }));
});
