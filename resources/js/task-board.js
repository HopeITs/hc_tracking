/**
 * Sortable.js bridges for the task board and calendar drag-and-drop.
 *
 * Sortable is imported lazily so pages without a task board never download it.
 * Each list keeps its own instance on the container element; Livewire morphs
 * the container's children in place after a drop, so the instance survives
 * the round trip untouched.
 */

// Shared across every drop list on the page (board columns and calendar
// days alike) so a task already mid-update can't be dropped again — the
// second drop is ignored until the first request resolves.
const pendingTaskIds = new Set();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('taskDropList', (group, onDrop) => ({
        sortable: null,

        async init() {
            const { default: Sortable } = await import('sortablejs');

            if (!this.$el.isConnected) {
                return;
            }

            this.sortable = new Sortable(this.$el, {
                group,
                animation: 150,
                ghostClass: 'opacity-40',
                dragClass: 'shadow-lg',
                filter: ':not([data-task-id])',
                onAdd: (event) => {
                    const taskId = Number(event.item.dataset.taskId);

                    if (!taskId || pendingTaskIds.has(taskId)) {
                        return;
                    }

                    pendingTaskIds.add(taskId);
                    // Immediate, precise feedback on exactly the dragged card —
                    // Livewire's response re-renders the whole list from the
                    // database anyway, which both clears this class and is the
                    // real "rollback" if the update turned out to be a no-op.
                    event.item.classList.add('opacity-50', 'pointer-events-none');

                    Promise.resolve(onDrop(this.$wire, taskId, this.$el.dataset)).finally(() => {
                        pendingTaskIds.delete(taskId);
                    });
                },
            });
        },

        destroy() {
            this.sortable?.destroy();
            this.sortable = null;
        },
    }));
});
