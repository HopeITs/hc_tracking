{{--
    The change requests a pressed change button lists (Y-09 slice 2), in place
    of the register's table, board, calendar or overview. Newest first; each
    row names the proposal, its number, the requester, the changed fields and
    the review state in words, with one Open link to the proposal's change
    request. Stacks as cards at phone width. Included by ⚡index.blade.php
    while `focus` is set.
--}}
@php
    $changeQueue = $this->changeQueue;
    $changeFields = $this->changeQueueFields;
    $waitingWords = [
        'finance_manager' => __('proposals.change.waiting_for_finance'),
        'meal_manager' => __('proposals.change.waiting_for_meal'),
    ];
@endphp

<section aria-labelledby="change-queue-heading" data-test="change-queue">
    <h2 id="change-queue-heading" class="sr-only">{{ __('proposals.change.queue_caption') }}</h2>

    @if ($changeQueue->isEmpty())
        <x-ui.empty-state
            icon="check-circle"
            :heading="$this->focus === 'review' ? __('proposals.change.queue_empty_review') : __('proposals.change.queue_empty_approve')"
        />
    @else
        <ul class="divide-y divide-secondary-soft overflow-hidden rounded-xl border border-secondary-soft bg-white dark:divide-line dark:border-line dark:bg-surface-1">
            @foreach ($changeQueue as $changeRequest)
                @php
                    $proposal = $changeRequest->proposal;
                    $waiting = $changeRequest->reviews
                        ->filter(fn ($review): bool => $review->verdict === null)
                        ->sortBy(fn ($review): int|false => array_search($review->slot, array_keys($waitingWords), true))
                        ->map(fn ($review): string => $waitingWords[$review->slot] ?? $review->slot)
                        ->values();
                @endphp
                <li
                    wire:key="change-queue-{{ $changeRequest->id }}"
                    data-change-queue-row="{{ $changeRequest->id }}"
                    class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $proposal?->name }}</span>
                            <x-ui.project-number :number="$proposal?->internal_project_number" class="text-xs text-secondary-medium dark:text-ink-muted" />
                        </p>
                        <p class="text-sm text-secondary-medium dark:text-ink-muted">
                            <span dir="auto">{{ $changeRequest->requester?->name ?: __('notifications.system') }}</span>
                            @if (($changeFields[$changeRequest->id] ?? []) !== [])
                                <span aria-hidden="true">·</span>
                                <span>{{ implode(__('proposals.change.list_separator'), $changeFields[$changeRequest->id]) }}</span>
                            @endif
                        </p>
                        <p class="text-sm font-semibold {{ $waiting->isEmpty() ? 'text-status-good' : 'text-status-warn' }}" data-change-queue-state>
                            {{ $waiting->isEmpty() ? __('proposals.change.queue_ready') : $waiting->implode(' · ') }}
                        </p>
                    </div>

                    @if ($proposal !== null)
                        <a
                            href="{{ route('proposals.show', ['proposal' => $proposal, 'change-request' => 1]) }}"
                            wire:navigate
                            class="inline-flex min-h-10 items-center justify-center self-start rounded-lg border border-secondary-soft px-4 text-sm font-semibold text-secondary-dark transition-colors hover:border-secondary-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium sm:self-center dark:border-line dark:text-ink"
                            aria-label="{{ __('proposals.change.queue_open_name', ['name' => $proposal->name]) }}"
                            data-test="change-queue-open"
                        >
                            {{ __('proposals.change.queue_open') }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
