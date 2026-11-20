{{--
    "History & approvals" section of proposals/show (stage 3b): Add a note,
    the status timeline (newest first, four shown then "Show all"), then
    folds for the concept note, each review stage (seen count, "Your
    review", Mark as seen, notes, vacant positions, add / remove reviewer),
    the need assessment, change requests, notes and the activity log.
    Every action is the existing one (ManagesProposalReviewers, the
    negotiation note dialog, DecidesProposalChange); the reviewer dialogs
    live in _sec-history-modals so Needs attention can open them while this
    section is folded. Included by _show-more.blade.php.
--}}
@use('App\Enums\ProposalStatus')
@use('App\Enums\StatusNoteKind')
@use('App\Support\ProposalStoryLink')
@php
    $proposal = $this->proposal;
    $isPayroll = $proposal->isPayroll();
    $noteAction = collect($this->headActions)->firstWhere('key', 'note');
    $canManageReviewers = auth()->user()->can('manageReviewers', $proposal);
    $addingStage = \App\Enums\ReviewStage::forAddingIn($proposal->status);
    $initialsOf = fn (?string $name): string => collect(preg_split('/\s+/u', trim((string) $name)) ?: [])->filter()->take(2)->map(fn (string $part): string => mb_substr($part, 0, 1))->implode('') ?: '?';
    $conceptNote = $proposal->concept_note;
    $conceptNoteFiles = $isPayroll ? collect() : $this->conceptNoteFiles;
    $conceptNoteParagraphs = filled($conceptNote)
        ? array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $conceptNote) ?: []), fn (string $part): bool => $part !== ''))
        : [];
    $hasConceptNote = $conceptNoteParagraphs !== [] || $conceptNoteFiles->isNotEmpty();
@endphp

@if ($noteAction !== null && ! $noteAction['disabled'])
    <div class="pp-row-acts">
        <button type="button" class="pp-btn pp-btn-sm" wire:click="openNegotiationModal" data-test="history-add-note">
            <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ $noteAction['label'] }}
        </button>
    </div>
@endif

@if ($this->finalReportAcceptance !== null)
    <p class="pp-small pp-ink-text" data-test="final-report-accepted">{{ __('proposals.final_report_accepted_by', ['date' => $this->finalReportAcceptance['date'], 'name' => $this->finalReportAcceptance['name']]) }}</p>
@endif

{{-- The status timeline: every move and note, newest first. --}}
@if ($this->statusNotes->isEmpty())
    <span class="pp-small" data-status-history>{{ __('proposals.no_status_history_yet') }}</span>
@else
    <div class="pp-timeline" x-data="{ all: false }" data-status-history>
        @foreach ($this->statusNotes as $note)
            @php
                $isNote = $note->kind !== StatusNoteKind::Transition;
            @endphp
            <div @class(['pp-tl', 'pp-tl-note' => $isNote, 'pp-tl-red' => ! $isNote && $note->status === ProposalStatus::Rejected]) wire:key="status-note-{{ $note->id }}" data-status-note="{{ $note->id }}" @if ($loop->index >= 4) x-show="all" x-cloak @endif>
                <div>
                    <b>{{ $isNote ? $note->kind->label() : $note->status?->label() }}</b>
                    <span class="pp-small">· {{ $note->date?->translatedFormat(__('formats.date')) }}@if ($isNote && $note->status !== null) · {{ $note->status->label() }}@endif</span>
                </div>
                @if (filled($note->context))
                    <div class="pp-tl-text" dir="auto">{{ $note->context }}</div>
                @endif
                @foreach ($note->media as $media)
                    <a href="{{ route('proposals.files.download', [$proposal, $media]) }}" class="pp-small pp-tl-file" wire:key="note-file-{{ $media->id }}" data-file="{{ $media->id }}" title="{{ $media->original_name }}">
                        <flux:icon.paper-clip variant="micro" class="size-3.5" aria-hidden="true" />{{ $media->original_name }}
                    </a>
                @endforeach
            </div>
        @endforeach
        @if ($this->statusNotes->count() > 4)
            <div>
                <button type="button" class="pp-link" x-on:click="all = ! all" x-text="all ? @js(__('proposals.sections.show_fewer')) : @js(__('proposals.sections.show_all', ['count' => $this->statusNotes->count()]))" data-test="history-show-all">{{ __('proposals.sections.show_all', ['count' => $this->statusNotes->count()]) }}</button>
            </div>
        @endif
    </div>
@endif

@unless ($isPayroll)
    {{-- Concept note --}}
    <details class="pp-sub" wire:ignore.self data-concept-note>
        <summary>
            <span class="pp-grow">{{ __('proposals.concept_note.title') }}</span>
            <span class="pp-small">{{ $hasConceptNote ? __('proposals.sections.concept_written', ['date' => $proposal->created_at?->translatedFormat(__('formats.date')) ?? '—']) : __('proposals.sections.concept_not_written') }}</span>
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            @if ($conceptNoteParagraphs !== [])
                <div class="pp-prose" data-concept-note-text>
                    @foreach ($conceptNoteParagraphs as $paragraph)
                        <p dir="auto">{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endif
            @foreach ($conceptNoteFiles as $media)
                <div class="pp-lrow" wire:key="concept-file-{{ $media->id }}" data-file="{{ $media->id }}">
                    <span class="pp-lrow-s"><b>{{ $media->original_name }}</b><span class="pp-small">{{ $media->collection->label() }}</span></span>
                    <span class="pp-lrow-r">
                        <a href="{{ route('proposals.files.download', [$proposal, $media]) }}" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}"><flux:icon.arrow-down-tray variant="micro" class="size-4" aria-hidden="true" /></a>
                    </span>
                </div>
            @endforeach
            @unless ($hasConceptNote)
                <span class="pp-small">{{ __('proposals.concept_note.nothing_recorded') }}</span>
            @endunless
        </div>
    </details>

    {{-- Review stages: where review links land (ProposalStoryLink). --}}
    <div id="{{ ProposalStoryLink::Reviewers }}" class="pp-plan pp-anchor" data-proposal-reviewers>
        @forelse ($this->reviewStages as $group)
            @php
                $realRows = collect($group['rows']);
                $seenCount = $realRows->filter(fn (array $row): bool => $row['seenAt'] !== null)->count();
                $myTurn = $group['isOpen'] && $realRows->contains(fn (array $row): bool => $row['isMine'] && $row['seenAt'] === null);
            @endphp
            <details class="pp-sub" wire:ignore.self @if ($group['isOpen']) open @endif wire:key="review-stage-{{ $group['stage']->value }}" data-review-stage="{{ $group['stage']->value }}">
                <summary>
                    <span class="pp-grow">{{ __('proposals.review_stage_heading', ['stage' => $group['stage']->label()]) }}</span>
                    @if ($realRows->isEmpty())
                        <span class="pp-pill pp-pill-red">{{ __('proposals.sections.flag_no_reviewers') }}</span>
                    @else
                        <span class="pp-pill">{{ __('proposals.sections.seen_of', ['seen' => $seenCount, 'total' => $realRows->count()]) }}</span>
                    @endif
                    @if ($myTurn)
                        <span class="pp-pill pp-pill-red">{{ __('proposals.sections.flag_your_review') }}</span>
                    @endif
                    <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="pp-sub-b">
                    @unless ($group['isOpen'])
                        <span class="pp-small">{{ __('proposals.notes_closed_reason') }}</span>
                    @endunless

                    @foreach ($group['rows'] as $row)
                        @php
                            $reviewerName = $row['employee']?->name ?? '#'.$row['employeeId'];
                        @endphp
                        <div @if ($row['isMine'] && $group['isOpen']) id="{{ ProposalStoryLink::MyReview }}" @endif class="pp-reviewer pp-anchor" wire:key="reviewer-{{ $row['id'] }}" data-reviewer-row="{{ $row['id'] }}">
                            <span class="pp-ava" aria-hidden="true">{{ $initialsOf($row['employee']?->name) }}</span>
                            <span class="min-w-0">
                                <b dir="auto">{{ $reviewerName }}</b>
                                <span class="pp-small">· {{ $row['slot']->label() }}</span>
                                @unless ($row['isActive'])
                                    <span class="pp-small" data-reviewer-inactive>· {{ __('proposals.inactive_account') }}</span>
                                @endunless
                                @if ($row['note'] !== null && ! ($row['isMine'] && $group['isOpen']))
                                    <span class="pp-small pp-own-line" dir="auto">“{{ $row['note'] }}” · {{ __('proposals.reviewer_note_on', ['date' => $row['noteAt']?->translatedFormat(__('formats.date')) ?? '—']) }}</span>
                                @endif
                            </span>
                            <span class="pp-lrow-r">
                                @if ($row['seenAt'] !== null)
                                    <span class="pp-pill pp-pill-ink" data-reviewer-state="seen" title="{{ __('proposals.reviewer_seen_on', ['date' => $row['seenAt']->translatedFormat(__('formats.date'))]) }}">{{ __('proposals.reviewer_seen_on', ['date' => $row['seenAt']->translatedFormat(__('formats.date'))]) }}</span>
                                @elseif ($row['isMine'] && ! $group['isOpen'])
                                    <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="markReviewed({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="markReviewed" data-mark-seen>{{ __('proposals.mark_as_seen') }}</button>
                                @else
                                    <span class="pp-pill" data-reviewer-state="unseen">{{ __('proposals.reviewer_not_seen') }}</span>
                                @endif
                                @if ($canManageReviewers && $row['isRemovable'])
                                    <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm" wire:click="confirmRemoveReviewer({{ $row['id'] }})" aria-label="{{ __('proposals.remove_reviewer_name', ['name' => $reviewerName]) }}" data-remove-reviewer="{{ $row['id'] }}">
                                        <flux:icon.trash variant="micro" class="size-4" aria-hidden="true" />
                                    </button>
                                @endif
                            </span>
                            @if ($row['isMine'] && $group['isOpen'])
                                <form wire:submit="{{ $row['seenAt'] === null ? 'markReviewed' : 'saveReviewerNote' }}({{ $row['id'] }})" class="pp-rnote" data-own-review>
                                    <label class="sr-only" for="reviewer-note-{{ $row['id'] }}">{{ __('proposals.reviewer_note') }}</label>
                                    <textarea id="reviewer-note-{{ $row['id'] }}" wire:model="reviewerNotes.{{ $row['id'] }}" rows="2" maxlength="2000" placeholder="{{ __('proposals.reviewer_note_placeholder') }}"></textarea>
                                    @if ($row['seenAt'] === null)
                                        <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary" wire:loading.attr="disabled" wire:target="markReviewed" data-mark-seen>{{ __('proposals.mark_as_seen') }}</button>
                                    @else
                                        <button type="submit" class="pp-btn pp-btn-sm" wire:loading.attr="disabled" wire:target="saveReviewerNote" data-save-review-note>{{ __('proposals.save_note') }}</button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    @endforeach

                    @foreach ($group['vacant'] as $vacant)
                        <div class="pp-reviewer" wire:key="vacant-{{ $group['stage']->value }}-{{ $vacant['slot']->value }}" data-vacant-slot="{{ $vacant['slot']->value }}" data-vacant-reason="{{ $vacant['isSet'] ? 'no-holder' : 'not-set' }}">
                            <span class="pp-ava pp-ava-vac" aria-hidden="true">?</span>
                            <span class="min-w-0">
                                <b>{{ $vacant['slot']->label() }}</b>
                                <span class="pp-small">· {{ $vacant['isSet'] ? __('proposals.position_vacant') : __('proposals.position_not_set') }}</span>
                                <span class="pp-small pp-own-line">{{ $vacant['isSet'] ? __('proposals.position_vacant_hint') : __('proposals.position_not_set_hint') }}</span>
                            </span>
                            <span></span>
                        </div>
                    @endforeach

                    @if ($group['rows'] === [] && $group['vacant'] === [])
                        <span class="pp-small">{{ __('proposals.no_reviewers_yet') }}</span>
                    @endif

                    @if ($canManageReviewers && $addingStage === $group['stage'])
                        @include('pages.proposals._sec-add-reviewer')
                    @endif
                </div>
            </details>
        @empty
            <span class="pp-small">{{ __('proposals.no_reviewers_yet') }}</span>
        @endforelse

        @if ($canManageReviewers && $addingStage !== null && ! collect($this->reviewStages)->contains(fn (array $group): bool => $group['stage'] === $addingStage))
            @include('pages.proposals._sec-add-reviewer')
        @endif
    </div>

    {{-- Need assessment --}}
    <details class="pp-sub" wire:ignore.self data-need-assessment>
        <summary>
            <span class="pp-grow">{{ __('proposals.need_assessment') }}</span>
            @if ($this->needAssessments->isEmpty())
                <span class="pp-pill pp-pill-red">{{ __('proposals.sections.missing') }}</span>
            @else
                <span class="pp-small" dir="auto">{{ $this->needAssessments->first()->original_name }}</span>
            @endif
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            @forelse ($this->needAssessments as $media)
                <div class="pp-lrow" wire:key="need-file-{{ $media->id }}" data-file="{{ $media->id }}">
                    <span class="pp-lrow-s">
                        @if ($media->isStored())
                            <button type="button" class="pp-file-link" wire:click="openPreview({{ $media->id }})" title="{{ $media->original_name }}"><b>{{ $media->original_name }}</b></button>
                        @else
                            <b>{{ $media->original_name }}</b>
                        @endif
                        <span class="pp-small">{{ $media->uploaded_at?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                    </span>
                    <span class="pp-lrow-r">
                        @if ($media->isStored())
                            <a href="{{ $this->documentDownloadUrl($media) }}" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}"><flux:icon.arrow-down-tray variant="micro" class="size-4" aria-hidden="true" /></a>
                        @endif
                    </span>
                </div>
            @empty
                <span class="pp-small">{{ __('proposals.no_need_assessment') }}</span>
            @endforelse
            @can('uploadNeedAssessment', $proposal)
                <div>
                    <button type="button" class="pp-btn pp-btn-sm" wire:click="openNeedAssessmentModal" data-test="add-need-assessment">
                        <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('proposals.add_need_assessment') }}
                    </button>
                </div>
            @endcan
        </div>
    </details>
@endunless

{{-- Change requests: asked for after approval (payroll too). --}}
@if ($proposal->status->isApprovedByDonor() || $this->changeRequestHistory->isNotEmpty())
    <details class="pp-sub" wire:ignore.self @if ($this->pendingChangeRequestId !== null) open @endif data-change-requests-fold>
        <summary>
            <span class="pp-grow">{{ __('proposals.change.chapter_title') }}</span>
            <span class="pp-small">{{ trans_choice('proposals.sections.n_requests', $this->changeRequestHistory->count(), ['count' => $this->changeRequestHistory->count()]) }}</span>
            @if ($this->pendingChangeRequestId !== null)
                <span class="pp-pill pp-pill-red">{{ __('proposals.sections.pending') }}</span>
            @endif
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            @include('pages.proposals._show-change-requests', ['bare' => true])
        </div>
    </details>
@endif

{{-- Notes written on the record --}}
@if (filled($proposal->notes))
    <details class="pp-sub" wire:ignore.self data-notes>
        <summary>
            <span class="pp-grow">{{ __('common.notes') }}</span>
            <span class="pp-small">{{ __('common.updated_date', ['date' => $proposal->updated_at?->translatedFormat(__('formats.date')) ?? '—']) }}</span>
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            <p class="pp-prose" dir="auto">{{ $proposal->notes }}</p>
        </div>
    </details>
@endif

{{-- The record's activity log, loaded only when asked for. --}}
<div class="pp-sub" data-record-activity>
    <button type="button" class="pp-sub-btn" wire:click="$toggle('showActivity')" aria-expanded="{{ $showActivity ? 'true' : 'false' }}" data-test="toggle-activity-log">
        <span class="pp-grow">{{ __('activity.title') }}</span>
        <span class="pp-small">{{ $showActivity ? __('activity.hide_log') : __('activity.show_log') }}</span>
        <svg @class(['pp-chev', 'pp-chev-up' => $showActivity]) width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    @if ($showActivity)
        <div class="pp-sub-b">
            <livewire:activity.timeline :subjects="['proposal' => [$this->proposal->id]]" :key="'proposal-activity-'.$this->proposal->id" />
        </div>
    @endif
</div>
