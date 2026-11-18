{{--
    The two reviews of one change request (Y-09, D-89a), in slot order: the verdict in words with who gave it
    and when, the note, "waiting", or "no holder" for a skipped slot. Included by _show-change-requests and
    _change-request-modal with $changeRequest (reviews.reviewer eager-loaded).
--}}
@php
    $orderedReviews = $changeRequest->reviews->sortBy(fn ($review) => array_search($review->slot, \App\Models\ProposalChangeRequest::ReviewSlots, true));
@endphp

<ul class="grid gap-2" data-change-reviews>
    @foreach ($orderedReviews as $review)
        <li class="grid gap-1 rounded-lg border border-secondary-soft px-3 py-2 text-sm dark:border-line" wire:key="change-review-{{ $review->id }}" data-change-review="{{ $review->slot }}" data-verdict="{{ $review->verdict?->value ?? 'waiting' }}">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold text-secondary-dark dark:text-ink">{{ __('proposals.change.slot.'.$review->slot) }}</span>
                @if ($review->verdict === null)
                    <span class="inline-flex items-center gap-1 rounded-md bg-status-warn-soft px-2 py-0.5 text-[11px] font-semibold text-status-warn">
                        <flux:icon.clock variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('proposals.change.review_waiting') }}
                    </span>
                @elseif ($review->verdict === \App\Enums\ChangeReviewVerdict::Skipped)
                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:border-line dark:text-ink-muted">{{ $review->verdict->label() }}</span>
                @else
                    <span @class([
                        'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-semibold',
                        'bg-status-good-soft text-status-good' => $review->verdict === \App\Enums\ChangeReviewVerdict::NoObjection,
                        'bg-status-warn-soft text-status-warn' => $review->verdict === \App\Enums\ChangeReviewVerdict::Remarks,
                    ])>
                        <flux:icon :name="$review->verdict === \App\Enums\ChangeReviewVerdict::NoObjection ? 'check-circle' : 'chat-bubble-left-ellipsis'" variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ $review->verdict->label() }}
                    </span>
                @endif
            </div>

            @if ($review->verdict === \App\Enums\ChangeReviewVerdict::Skipped)
                <p class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.no_holder') }}</p>
            @elseif ($review->verdict !== null)
                <p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.review_by', ['name' => $review->reviewer?->name ?? '—', 'date' => $review->reviewed_at?->translatedFormat(__('formats.date')) ?? '—']) }}</p>
                @if (filled($review->note))
                    <p class="max-w-[70ch] whitespace-pre-line text-secondary-dark dark:text-ink" dir="auto">{{ $review->note }}</p>
                @endif
            @endif
        </li>
    @endforeach
</ul>
