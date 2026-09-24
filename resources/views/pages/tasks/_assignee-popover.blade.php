@php
    use Illuminate\Support\Js;
@endphp

{{--
    One shared assignee popover for the whole list, opened by whichever
    row's trigger was clicked (see the `$store.assigneePicker` calls in
    _list-row.blade.php / _list-card.blade.php). Rendering `$this->userOptions`
    once here instead of once per row is the point — see assignee-picker.js
    for why that used to make assignee picking slow.
--}}
<template x-teleport="body">
    <div
        x-data
        x-show="$store.assigneePicker.open"
        x-cloak
        x-on:click.outside="$store.assigneePicker.close()"
        x-on:keydown.escape.window="$store.assigneePicker.close()"
        x-transition.opacity.duration.100ms
        x-bind:style="`top: ${$store.assigneePicker.top}px; left: ${$store.assigneePicker.left}px;`"
        role="listbox"
        aria-multiselectable="true"
        aria-label="{{ __('tasks.assignees') }}"
        class="fixed z-50 w-64 rounded-lg border border-secondary-soft bg-white p-1 shadow-lg dark:border-secondary-medium dark:bg-secondary-dark"
    >
        <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
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
            @foreach ($this->userOptions as $userOption)
                <button
                    type="button"
                    role="option"
                    x-bind:aria-selected="$store.assigneePicker.isAssigned({{ $userOption['id'] }}) ? 'true' : 'false'"
                    x-on:click="$store.assigneePicker.toggle($wire, {{ $userOption['id'] }})"
                    x-show="$store.assigneePicker.optionSearch === '' || {{ Js::from(mb_strtolower($userOption['name'].' '.$userOption['secondary_name'])) }}.includes($store.assigneePicker.optionSearch.toLowerCase())"
                    wire:key="assignee-popover-option-{{ $userOption['id'] }}"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                    data-test="assignee-popover-option-{{ $userOption['id'] }}"
                >
                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                    <flux:icon.check x-show="$store.assigneePicker.isAssigned({{ $userOption['id'] }})" class="size-4 shrink-0 text-primary" />
                </button>
            @endforeach
        </div>
    </div>
</template>
