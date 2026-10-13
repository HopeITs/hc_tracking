@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;

    $check = FluxMarkup::icon('check', 'outline', 'ms-auto size-4 shrink-0 text-secondary-medium');
@endphp

{{--
    One shared menu for the task list's status and priority triggers, opened by
    whichever trigger was clicked (see the `$store.taskMenu` calls in
    _list-row.blade.php, the composers and the mobile cards). Rendered once per
    page instead of once per row — see task-menu.js.
--}}
<template x-teleport="body">
    <div
        x-data
        x-show="$store.taskMenu.open"
        x-cloak
        x-on:click.outside="$store.taskMenu.close()"
        x-on:keydown.escape.window="$store.taskMenu.close()"
        x-transition.opacity.duration.100ms
        x-bind:style="`top: ${$store.taskMenu.top}px; left: ${$store.taskMenu.left}px;`"
        role="menu"
        class="fixed z-50 min-w-44 rounded-lg border border-secondary-soft bg-white p-1 shadow-lg dark:border-line dark:bg-surface-3 dark:shadow-raised"
        data-test="task-option-menu"
    >
        <div x-show="$store.taskMenu.kind === 'status'" class="flex flex-col">
            @foreach (TaskStatus::cases() as $statusCase)
                <button
                    type="button"
                    role="menuitemradio"
                    x-bind:aria-checked="$store.taskMenu.current === '{{ $statusCase->value }}' ? 'true' : 'false'"
                    x-on:click="$store.taskMenu.pick($wire, '{{ $statusCase->value }}')"
                    class="{{ FluxMarkup::MenuItem }} cursor-pointer hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                    data-test="task-status-option-{{ $statusCase->value }}"
                >
                    {{ FluxMarkup::statusPill($statusCase) }}
                    <span x-show="$store.taskMenu.current === '{{ $statusCase->value }}'" style="display: none" class="ms-auto">{{ $check }}</span>
                </button>
            @endforeach
        </div>
        <div x-show="$store.taskMenu.kind === 'priority'" class="flex flex-col" style="display: none">
            @foreach (TaskPriority::cases() as $priorityCase)
                <button
                    type="button"
                    role="menuitemradio"
                    x-bind:aria-checked="$store.taskMenu.current === '{{ $priorityCase->value }}' ? 'true' : 'false'"
                    x-on:click="$store.taskMenu.pick($wire, '{{ $priorityCase->value }}')"
                    class="{{ FluxMarkup::MenuItem }} cursor-pointer gap-2 hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                    data-test="task-priority-option-{{ $priorityCase->value }}"
                >
                    {{ FluxMarkup::priorityFlag($priorityCase) }}
                    <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                    <span x-show="$store.taskMenu.current === '{{ $priorityCase->value }}'" style="display: none" class="ms-auto">{{ $check }}</span>
                </button>
            @endforeach
        </div>
    </div>
</template>
