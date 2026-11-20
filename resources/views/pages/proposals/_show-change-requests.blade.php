{{--
    The Change requests chapter of proposals/show (Y-09 slice 2, DecidesProposalChange): every change asked
    for after approval, newest first, each with its status in words, the requester, the reason, the
    "from → to" list, both reviews and the decision. All lists come from one ProposalChangeLines::forMany()
    call. Included by _show-status-tab.blade.php; the page's top PHP block defines $mutedText, $inkText and
    $hairline.
--}}
@php
    /* $bare: inside the proposal page's History fold (stage 3b), which already carries the title. */
    $bare ??= false;
@endphp
@if ($this->proposal->status->isApprovedByDonor() || $this->changeRequestHistory->isNotEmpty())
    <section @unless ($bare) aria-labelledby="change-requests-heading" @endunless @class(['min-w-0', 'mt-10' => ! $bare]) data-test="change-requests-chapter">
        @if ($bare)
            <p class="text-xs {{ $mutedText }}">{{ __('proposals.change.chapter_hint') }}</p>
        @else
            <div class="border-b pb-3 {{ $hairline }}">
                <h2 id="change-requests-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.change.chapter_title') }}</h2>
                <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.change.chapter_hint') }}</p>
            </div>
        @endif

        @if ($this->changeRequestHistory->isEmpty())
            <p class="mt-4 text-sm {{ $mutedText }}" data-test="change-requests-empty">{{ __('proposals.change.chapter_empty') }}</p>
        @else
            <ol class="mt-2 divide-y {{ $hairline }}">
                @foreach ($this->changeRequestHistory as $changeRequest)
                    <li class="grid min-w-0 gap-3 py-4" wire:key="change-request-{{ $changeRequest->id }}" data-change-request="{{ $changeRequest->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.enum-badge :case="$changeRequest->status" />
                                <span class="text-xs tabular-nums {{ $mutedText }}">{{ __('proposals.change.requested_by', ['name' => $changeRequest->requester?->name ?? '—', 'date' => $changeRequest->created_at?->translatedFormat(__('formats.date')) ?? '—']) }}</span>
                            </div>
                            @if ($changeRequest->isPending())
                                <x-ui.button size="sm" icon="document-magnifying-glass" class="min-h-10 touch-manipulation" wire:click="openChangeRequestModal({{ $changeRequest->id }})" data-test="chapter-open-change-request">{{ __('proposals.change.open_button') }}</x-ui.button>
                            @endif
                        </div>

                        <div class="min-w-0">
                            <h3 class="type-label {{ $mutedText }}">{{ __('proposals.change.reason_heading') }}</h3>
                            <p class="mt-1 max-w-[70ch] text-sm whitespace-pre-line {{ $inkText }}" dir="auto">{{ $changeRequest->reason }}</p>
                        </div>

                        <div class="min-w-0">
                            <h3 class="type-label {{ $mutedText }}">{{ __('proposals.change.changes_heading') }}</h3>
                            <x-proposals.change-list :lines="$this->changeRequestHistoryLines[$changeRequest->id] ?? []" class="mt-1" />
                        </div>

                        <div class="min-w-0">
                            <h3 class="type-label {{ $mutedText }}">{{ __('proposals.change.reviews_heading') }}</h3>
                            <div class="mt-1">
                                @include('pages.proposals._change-request-reviews', ['changeRequest' => $changeRequest])
                            </div>
                        </div>

                        @unless ($changeRequest->isPending())
                            <div class="min-w-0" data-change-decision>
                                <h3 class="type-label {{ $mutedText }}">{{ __('proposals.change.decision_heading') }}</h3>
                                <p class="mt-1 text-xs tabular-nums {{ $mutedText }}">{{ __('proposals.change.decided_by', ['name' => $changeRequest->decider?->name ?? '—', 'date' => $changeRequest->decided_at?->translatedFormat(__('formats.date')) ?? '—']) }}</p>
                                @if (filled($changeRequest->decision_note))
                                    <p class="mt-1 max-w-[70ch] text-sm whitespace-pre-line {{ $inkText }}" dir="auto">{{ $changeRequest->decision_note }}</p>
                                @endif
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endif
