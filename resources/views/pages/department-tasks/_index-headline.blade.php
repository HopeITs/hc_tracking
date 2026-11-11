{{--
    The three headline figures of the Department Tasks page, each a filter:
    open tasks past their due date, deliverables the viewer can hand over
    now, and deliverables waiting for the viewer's review. Pressing one again
    shows every task. A task can be in two figures (an overdue deliverable),
    so they do not add up; each says how many open tasks it is out of.
    Included by ⚡index.blade.php.
--}}
@php
    $counts = $this->focusCounts;

    $headlines = [
        [
            'key' => 'overdue',
            'label' => __('department_tasks.focus_overdue'),
            'note' => __('department_tasks.focus_overdue_note'),
            // 30px at weight 800 is large text, so status-critical clears 3:1 on dark.
            'tone' => 'text-status-critical',
            'dot' => 'bg-primary dark:bg-status-critical',
        ],
        [
            'key' => 'deliver',
            'label' => __('department_tasks.focus_deliver'),
            'note' => __('department_tasks.focus_deliver_note'),
            'tone' => 'text-status-warn',
            'dot' => 'bg-status-warn',
        ],
        [
            'key' => 'review',
            'label' => __('department_tasks.focus_review'),
            'note' => __('department_tasks.focus_review_note'),
            'tone' => 'text-secondary-dark dark:text-ink',
            'dot' => 'bg-secondary-medium dark:bg-ink-muted',
        ],
    ];
@endphp

<section aria-labelledby="department-tasks-headline-heading" class="mb-8" data-department-tasks-headline>
    <h2 id="department-tasks-headline-heading" class="sr-only">{{ __('department_tasks.what_needs_a_hand') }}</h2>

    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($headlines as $headline)
            @php
                $count = $counts[$headline['key']];
                $isActive = $focus === $headline['key'];
                // Nothing here: the figure still reads 0 and stays a tab stop,
                // but pressing it would only show an empty list.
                $isUnavailable = $count === 0 && ! $isActive;
            @endphp
            <li>
                <button
                    type="button"
                    @unless ($isUnavailable)
                        wire:click="toggleFocus('{{ $headline['key'] }}')"
                    @else
                        aria-disabled="true"
                        title="{{ __('department_tasks.nothing_here') }}"
                    @endunless
                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                    @class([
                        'group flex w-full min-h-[4.5rem] flex-col justify-center rounded-xl border p-4 text-start transition-colors duration-150 touch-manipulation focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:border-line',
                        'border-secondary-soft hover:bg-board-head dark:hover:bg-white/5' => ! $isActive && ! $isUnavailable,
                        'border-secondary-dark bg-board-head dark:border-ink dark:bg-white/5' => $isActive,
                        'border-secondary-soft cursor-default opacity-60' => $isUnavailable,
                    ])
                    data-headline="{{ $headline['key'] }}"
                >
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                        <span class="size-2 shrink-0 rounded-full {{ $headline['dot'] }}" aria-hidden="true"></span>
                        {{ $headline['label'] }}
                    </span>
                    <span class="mt-1.5 flex items-baseline gap-2">
                        <span @class(['text-3xl leading-none font-extrabold tracking-tight tabular-nums', $headline['tone']])>{{ $count }}</span>
                        <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('department_tasks.of_open_tasks', $counts['open'], ['count' => $counts['open']]) }}</span>
                    </span>
                    <span @class(['mt-1 block text-[11px] text-secondary-medium dark:text-ink-muted group-aria-pressed:underline', 'group-hover:underline' => ! $isUnavailable])>{{ $headline['note'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>
</section>
