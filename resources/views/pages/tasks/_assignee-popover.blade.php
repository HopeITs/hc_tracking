{{--
    One shared assignee popover for the whole list, opened by whichever
    row's trigger was clicked (see the `$store.assigneePicker` calls in
    _list-row.blade.php / _list-card.blade.php). The options are not rendered
    by the server at all: they are drawn client-side from the shared
    employeeOptions store (see form-fields.js), so no Livewire response
    carries the employee list — see assignee-picker.js for why that used to
    make assignee picking slow.
--}}
<template x-teleport="body">
    <div
        x-data="assigneePopover"
        data-employees-url="{{ route('employees.picker-options', ['l' => app()->getLocale()]) }}"
        x-show="$store.assigneePicker.open"
        x-cloak
        x-on:click.outside="$store.assigneePicker.close()"
        x-on:keydown.escape.window="$store.assigneePicker.close()"
        x-transition.opacity.duration.100ms
        x-bind:style="`top: ${$store.assigneePicker.top}px; left: ${$store.assigneePicker.left}px;`"
        role="listbox"
        aria-multiselectable="true"
        aria-label="{{ __('tasks.assignees') }}"
        class="fixed z-50 w-64 rounded-lg border border-secondary-soft bg-white p-1 shadow-lg dark:border-line dark:bg-surface-3 dark:shadow-raised"
    >
        <div class="border-b border-secondary-soft p-1.5 dark:border-line">
            <x-ui.input
                type="search"
                size="sm"
                icon="magnifying-glass"
                x-model="$store.assigneePicker.optionSearch"
                x-on:keydown.enter.prevent
                :aria-label="__('tasks.assignees')"
                :placeholder="__('tasks.assignees')"
            />
        </div>
        <div class="max-h-56 overflow-y-auto overscroll-contain">
            <div
                wire:ignore
                x-ref="employeeList"
                class="contents"
                x-on:click="pickEmployee($event, $wire)"
                data-role="option"
                data-item-class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                data-check-class="shrink-0 size-4 text-primary dark:text-brand-text"
            ></div>
        </div>
    </div>
</template>
