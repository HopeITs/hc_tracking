@props(['case'])

{{-- A bolder, filled status treatment for dense task rows/boards. Deliberately
     separate from x-ui.enum-badge (used app-wide) so this visual weight stays
     scoped to task views instead of rippling into every other page. --}}
@php
    [$classes, $icon] = match ($case) {
        \App\Enums\TaskStatus::Todo => ['bg-white text-secondary-dark ring-1 ring-inset ring-secondary-soft dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium', 'stop'],
        \App\Enums\TaskStatus::InProgress => ['bg-status-active text-white', 'play'],
        \App\Enums\TaskStatus::UnderReview => ['bg-status-warn text-white', 'eye'],
        \App\Enums\TaskStatus::Done => ['bg-status-good text-white', 'check'],
    };
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase whitespace-nowrap $classes") }}>
    <flux:icon :name="$icon" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
    <span class="truncate">{{ $case->label() }}</span>
</span>
