{{--
    One person as a card in the Supervision tab's drill-down. Included by
    ⚡supervisions.blade.php; reads $nodes from it and takes $memberId,
    $link (the edge from the team's supervisor, null at the top level) and $teamId.
    A card with a team opens it; an added link can be removed from the card.
--}}
@php
    $member = $nodes[$memberId];
    $hasTeam = $member['children'] !== [];
    $isAdded = $link !== null && ! $link['fromHr'];
@endphp

<div
    wire:key="supervision-card-{{ $link === null ? 'top' : 'in' }}-{{ $memberId }}-{{ $link['supervisionId'] ?? 'hr' }}"
    @if ($link !== null && $link['fromHr']) data-supervision-hr="{{ $teamId }}-{{ $memberId }}" @endif
    @if ($hasTeam)
        role="button" tabindex="0"
        x-on:click="open({{ $memberId }})"
        x-on:keydown.enter.prevent="open({{ $memberId }})"
        x-on:keydown.space.prevent="open({{ $memberId }})"
    @endif
    @class([
        'group flex min-h-16 min-w-0 items-center gap-3 rounded-lg border bg-white px-3 py-2 transition dark:bg-surface-1',
        'cursor-pointer border-secondary-light hover:border-primary/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:border-line-strong dark:hover:border-brand/60' => $hasTeam,
        'border-secondary-soft dark:border-line' => ! $hasTeam,
    ])
>
    <span @class([
        'flex size-9 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-xs font-bold text-secondary-medium dark:bg-surface-3 dark:text-ink-muted',
        'ring-[1.5px] ring-primary dark:ring-brand' => $isAdded,
        'opacity-60' => $member['inactive'],
    ]) aria-hidden="true">{{ $member['initials'] }}</span>

    <span class="min-w-0 flex-1">
        <span @class([
            'block truncate text-sm text-secondary-dark dark:text-ink',
            'font-bold' => $hasTeam,
            'line-through decoration-secondary-light' => $member['inactive'],
        ])>{{ $member['name'] }}</span>

        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
            @if ($member['inactive'])
                <span class="inline-flex items-center gap-1 font-semibold text-secondary-medium dark:text-ink-muted">
                    <flux:icon.no-symbol variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ __('users.supervision_inactive') }}
                </span>
            @endif
            @if ($link !== null)
                @if ($link['fromHr'])
                    <span class="inline-flex items-center gap-1 text-secondary-medium dark:text-ink-muted" title="{{ $link['climbed'] ? __('users.supervision_climbed_help') : __('users.supervision_hr_read_only') }}">
                        <flux:icon.building-office variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('users.supervision_from_hr') }}@if ($link['climbed']) · {{ __('users.supervision_climbed') }}@endif
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 font-semibold text-status-critical">
                        <flux:icon.link variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('users.supervision_filter_added') }}
                    </span>
                @endif
            @elseif ($hasTeam)
                <span class="text-secondary-medium dark:text-ink-muted">{{ trans_choice('users.supervision_total', $member['total'], ['count' => $member['total']]) }}</span>
            @endif
        </span>
    </span>

    @if ($isAdded)
        <button type="button" x-on:click.stop wire:click="confirmRemove({{ $link['supervisionId'] }})" class="shrink-0 rounded p-1.5 text-secondary-medium opacity-0 transition hover:bg-primary/10 hover:text-primary group-hover:opacity-100 focus-visible:opacity-100 max-sm:opacity-100 dark:text-ink-muted dark:hover:text-brand-text" title="{{ __('users.supervision_remove') }}" aria-label="{{ __('users.supervision_remove') }}: {{ $member['name'] }}" data-test="remove-supervision-{{ $link['supervisionId'] }}">
            <flux:icon.x-mark variant="micro" class="size-4" aria-hidden="true" />
        </button>
    @endif

    @if ($hasTeam)
        <span class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-secondary-soft px-2 py-0.5 text-xs font-semibold tabular-nums text-secondary-dark dark:bg-surface-3 dark:text-ink">
            {{ count($member['children']) }}
            <flux:icon.chevron-right variant="micro" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
        </span>
    @endif
</div>
