{{--
    The task dialog of calendar/index (x-tasks.composer, shared with the
    tasks page) and its delete confirmation. Included by ⚡index.blade.php.
--}}
    <x-tasks.composer
        :open="$showTaskModal"
        model="showTaskModal"
        close="closeTaskModal"
        :editing="$editingTaskId !== null"
        :source-type-options="$showTaskModal ? $this->sourceTypeOptions : []"
        :source-options="$showTaskModal ? collect($this->sourceOptions)->mapWithKeys(fn ($sourceOption) => [$sourceOption->id => $sourceOption->name])->all() : []"
        :proof-locked="$editingTaskId !== null && $editingTaskCreatedBy !== auth()->id()"
        test-prefix="calendar-task-form"
        save-test="calendar-save-task-button"
    />

    <x-ui.confirm-modal
        permanent
        wire:model="showTaskDeleteModal"
        :title="__('tasks.delete_this_task')"
        confirm="deleteTask"
        :confirm-label="__('tasks.delete_task')"
        confirm-test="calendar-confirm-delete-task"
    />
