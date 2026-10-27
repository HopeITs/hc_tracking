{{--
    The Reviewers panel of proposals/show (T07, ManagesProposalReviewers):
    each review stage with its reviewers, Mark as Seen and the note for the
    viewer's own row, adding and removing extra reviewers, vacant default
    slots, and the need assessment. Included by _show-status-tab.blade.php;
    the page's top PHP block defines $mutedText, $inkText and $hairline.
--}}
@php
    $canManageReviewers = auth()->user()->can('manageReviewers', $this->proposal);
    $addingStage = \App\Enums\ReviewStage::forAddingIn($this->proposal->status);
@endphp

<section aria-labelledby="reviewers-heading" class="min-w-0" data-proposal-reviewers>
    <div class="flex flex-wrap items-end justify-between gap-3 border-b pb-3 {{ $hairline }}">
        <div class="min-w-0">
            <h2 id="reviewers-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.reviewers') }}</h2>
            <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.reviewers_hint') }}</p>
        </div>
    </div>

    @if ($this->reviewStages === [])
        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_reviewers_yet') }}</p>
    @endif

    @foreach ($this->reviewStages as $group)
        <div class="mt-5 min-w-0" wire:key="review-stage-{{ $group['stage']->value }}" data-review-stage="{{ $group['stage']->value }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-bold {{ $inkText }}">{{ __('proposals.review_stage_heading', ['stage' => $group['stage']->label()]) }}</h3>
                @unless ($group['isOpen'])
                    <p class="text-xs {{ $mutedText }}">{{ __('proposals.notes_closed_reason') }}</p>
                @endunless
            </div>

            <ul class="mt-2 divide-y {{ $hairline }}">
                @foreach ($group['rows'] as $row)
                    <li class="flex flex-col gap-3 py-3 @2xl/detail:flex-row @2xl/detail:items-start" wire:key="reviewer-{{ $row['id'] }}" data-reviewer-row="{{ $row['id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.employee-chip :employee="$row['employee']" compact />
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold {{ $mutedText }}">{{ $row['slot']->label() }}</span>
                                @if ($row['seenAt'] !== null)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-status-good-soft px-2 py-0.5 text-[11px] font-semibold text-status-good" data-reviewer-state="seen">
                                        <flux:icon.check-circle variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ __('proposals.reviewer_seen_on', ['date' => $row['seenAt']->translatedFormat(__('formats.date'))]) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-md bg-status-warn-soft px-2 py-0.5 text-[11px] font-semibold text-status-warn" data-reviewer-state="unseen">
                                        <flux:icon.eye-slash variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ __('proposals.reviewer_not_seen') }}
                                    </span>
                                @endif
                                @unless ($row['isActive'])
                                    <span class="inline-flex items-center gap-1 rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold {{ $mutedText }}" data-reviewer-inactive>
                                        <flux:icon.no-symbol variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ __('proposals.inactive_account') }}
                                    </span>
                                @endunless
                            </div>

                            @if ($row['isMine'] && $group['isOpen'])
                                <form wire:submit="{{ $row['seenAt'] === null ? 'markReviewed' : 'saveReviewerNote' }}({{ $row['id'] }})" class="mt-3 grid max-w-xl gap-2" data-own-review>
                                    <x-ui.form-textarea wire:model="reviewerNotes.{{ $row['id'] }}" :label="__('proposals.reviewer_note')" :placeholder="__('proposals.reviewer_note_placeholder')" rows="2" maxlength="2000" />
                                    <div class="flex flex-wrap gap-2">
                                        @if ($row['seenAt'] === null)
                                            <x-ui.button type="submit" tone="primary" size="sm" icon="eye" class="min-h-10 touch-manipulation" wire:loading.attr="disabled" wire:target="markReviewed" data-mark-seen>{{ __('proposals.mark_as_seen') }}</x-ui.button>
                                        @else
                                            <x-ui.button type="submit" size="sm" icon="check" class="min-h-10 touch-manipulation" wire:loading.attr="disabled" wire:target="saveReviewerNote" data-save-review-note>{{ __('proposals.save_note') }}</x-ui.button>
                                        @endif
                                    </div>
                                </form>
                            @else
                                @if ($row['isMine'] && $row['seenAt'] === null)
                                    <x-ui.button size="sm" icon="eye" class="mt-3 min-h-10 touch-manipulation" wire:click="markReviewed({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="markReviewed" data-mark-seen>{{ __('proposals.mark_as_seen') }}</x-ui.button>
                                @endif
                                @if ($row['note'] !== null)
                                    <p class="mt-2 max-w-[70ch] text-sm whitespace-pre-line {{ $inkText }}" dir="auto">{{ $row['note'] }}</p>
                                    <p class="mt-0.5 text-xs tabular-nums {{ $mutedText }}">{{ __('proposals.reviewer_note_on', ['date' => $row['noteAt']?->translatedFormat(__('formats.date')) ?? '—']) }}</p>
                                @endif
                            @endif
                        </div>

                        @if ($canManageReviewers && $row['isRemovable'])
                            <x-ui.button size="sm" tone="ghost" icon="x-mark" class="min-h-10 touch-manipulation self-start" wire:click="confirmRemoveReviewer({{ $row['id'] }})" :aria-label="__('proposals.remove_reviewer_name', ['name' => $row['employee']?->name ?? '#'.$row['employeeId']])" data-remove-reviewer="{{ $row['id'] }}" />
                        @endif
                    </li>
                @endforeach

                @foreach ($group['vacant'] as $vacantSlot)
                    <li class="flex flex-wrap items-center gap-2 py-3" wire:key="vacant-{{ $group['stage']->value }}-{{ $vacantSlot->value }}" data-vacant-slot="{{ $vacantSlot->value }}">
                        <span class="inline-flex items-center gap-1 rounded-md bg-status-warn-soft px-2 py-0.5 text-[11px] font-semibold text-status-warn">
                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ __('proposals.position_vacant') }}
                        </span>
                        <span class="text-sm font-medium {{ $inkText }}">{{ $vacantSlot->label() }}</span>
                        <span class="w-full text-xs {{ $mutedText }}">{{ __('proposals.position_vacant_hint') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @if ($canManageReviewers && $addingStage !== null)
        <form wire:submit="addReviewer" class="mt-5 flex flex-col gap-2 @xl/detail:flex-row @xl/detail:items-end" data-add-reviewer>
            <div class="min-w-0 flex-1">
                <x-ui.form-select wire:model="reviewerEmployeeId" :label="__('proposals.reviewer_to_add')" :placeholder="__('proposals.choose_a_reviewer')" employees />
            </div>
            <x-ui.button type="submit" tone="primary" icon="user-plus" class="min-h-10 touch-manipulation" wire:loading.attr="disabled" wire:target="addReviewer" data-test="add-reviewer">{{ __('proposals.add_reviewer') }}</x-ui.button>
        </form>
    @endif

    <div class="mt-8 min-w-0" data-need-assessment>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-bold {{ $inkText }}">{{ __('proposals.need_assessment') }}</h3>
            @can('uploadNeedAssessment', $this->proposal)
                <x-ui.button size="sm" icon="arrow-up-tray" class="min-h-10 touch-manipulation" wire:click="openNeedAssessmentModal" data-test="add-need-assessment">{{ __('proposals.add_need_assessment') }}</x-ui.button>
            @endcan
        </div>

        @if ($this->needAssessments->isEmpty())
            <p class="mt-2 text-sm {{ $mutedText }}">{{ __('proposals.no_need_assessment') }}</p>
        @else
            <ul class="mt-2 divide-y {{ $hairline }}">
                @foreach ($this->needAssessments as $media)
                    @include('pages.proposals._file-row', ['media' => $media, 'proposal' => $this->proposal, 'downloadUrl' => $this->documentDownloadUrl($media), 'owner' => null, 'editable' => false, 'inkText' => $inkText, 'mutedText' => $mutedText])
                @endforeach
            </ul>
        @endif
    </div>

    <x-ui.confirm-modal
        wire:model="showRemoveReviewerModal"
        close="closeRemoveReviewerModal"
        :title="__('proposals.remove_reviewer')"
        :message="__('proposals.remove_reviewer_consequence')"
        confirm="removeReviewer"
        :confirm-label="__('proposals.remove_reviewer')"
        confirm-test="confirm-remove-reviewer"
    >
        @error('removeReviewer')
            <p class="rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    </x-ui.confirm-modal>

    <x-ui.modal
        wire:model="showNeedAssessmentModal"
        size="md"
        :title="__('proposals.add_need_assessment')"
        :description="__('reports.allowed_file_types_and_size', ['types' => \Illuminate\Support\Str::upper(implode(', ', \App\Enums\FileType::values())), 'size' => '5 MB'])"
        submit="saveNeedAssessment"
        close="closeNeedAssessmentModal"
        required-note
    >
        @if ($showNeedAssessmentModal)
            <x-ui.form-file
                wire:model="newFile"
                :label="__('proposals.choose_a_file')"
                :accept="collect(\App\Enums\FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                required
            />
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveNeedAssessment" data-test="save-need-assessment">{{ __('proposals.upload_file') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
