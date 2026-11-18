@use('Illuminate\Support\Js')
{{--
    The tail of an inline "+ Add task" row (list, phone list, board): a
    "Related to" chip naming where the task will be filed, and a "More
    fields" button that opens the full drawer with whatever was typed.
    The row's container carries data-quick-add-row; its title input is the
    first text input inside it.

    @var string|null $defaultStatus
    @var string $rowKey
    @var string $testKey
--}}
@php
    $relatedToLabel = $this->inlineDefaultSourceLabel;
    $typedTitle = "\$el.closest('[data-quick-add-row]').querySelector('input[type=text]').value";
@endphp
@if ($relatedToLabel !== null)
    <span
        class="inline-flex max-w-48 min-w-0 items-center gap-1 rounded-full bg-secondary-soft px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:bg-white/10 dark:text-ink-muted"
        title="{{ __('tasks.source_type') }}: {{ $relatedToLabel }}"
        data-test="task-quick-add-related-{{ $testKey }}"
    >
        <flux:icon name="link" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        <span dir="auto" class="truncate">{{ $relatedToLabel }}</span>
    </span>
@endif
<button
    type="button"
    x-on:click="$wire.openQuickTaskInDrawer({{ $typedTitle }}, {{ Js::from($defaultStatus) }}, {{ Js::from($rowKey) }})"
    class="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-semibold text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink"
    data-test="task-quick-add-more-{{ $testKey }}"
>
    <flux:icon name="arrows-pointing-out" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
    {{ __('tasks.more_fields') }}
</button>
