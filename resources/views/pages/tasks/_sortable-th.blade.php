@php
    /**
     * $field, $label, and optionally $class are passed via @include.
     * $sortField/$sortDirection come from the parent scope automatically.
     */
    $isActive = $sortField === $field;
    $nextDirectionLabel = $isActive && $sortDirection === 'asc' ? __('tasks.sort_descending') : __('tasks.sort_ascending');
@endphp

<th class="{{ $class ?? '' }}">
    <button
        type="button"
        wire:click="setSort('{{ $field }}')"
        class="inline-flex w-full cursor-pointer items-center gap-1 text-start hover:text-secondary-dark focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary dark:hover:text-ink {{ $isActive ? 'text-secondary-dark dark:text-ink' : '' }}"
        aria-label="{{ __('tasks.sort_by_field', ['field' => $label]) }} — {{ $nextDirectionLabel }}"
        data-test="task-sort-header-{{ $field }}"
        data-sort-direction="{{ $isActive ? $sortDirection : 'none' }}"
    >
        <span class="truncate">{{ $label }}</span>
        @if ($isActive)
            <flux:icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        @else
            <flux:icon.chevron-up-down variant="micro" class="size-3 shrink-0 text-secondary-light opacity-70" aria-hidden="true" />
        @endif
    </button>
</th>
