{{--
    The change request dialog of proposals/show (Y-09 slice 2, DecidesProposalChange): the reason, the
    "from → to" list (ProposalChangeLines, the same list the requester confirmed), both reviews, then what
    the viewer may do: review in their own slot, approve or reject (the PR Manager), withdraw (the
    requester), or read. Included by _show-modals.blade.php; the body renders only while it is open.
--}}
@php
    $deciding = $showChangeRequestModal ? $this->decidingChangeRequest : null;
@endphp

<x-ui.modal
    wire:model="showChangeRequestModal"
    size="md"
    :title="__('proposals.change.decide_title')"
    close="closeChangeRequestModal"
    :cancel-label="__('common.close')"
    data-test="change-request-modal"
>
    @if ($showChangeRequestModal && $deciding !== null)
        @php
            $waitingReviews = $this->waitingChangeReviews($deciding);
            $reviewSlot = $deciding->isPending() ? $this->changeReviewSlot : null;
            $canDecide = $deciding->isPending() && $this->canDecideChange;
            $isRequester = $deciding->isPending() && $deciding->requested_by === auth()->id();
            $addsPlace = collect($deciding->changes)->contains(fn (array $change): bool => ($change['field'] ?? null) === 'locations' && ($change['from'] ?? null) === null);
        @endphp

        <div class="grid gap-5" data-change-request-body="{{ $deciding->id }}">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$deciding->status" />
                <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.requested_by', ['name' => $deciding->requester?->name ?? '—', 'date' => $deciding->created_at?->translatedFormat(__('formats.date')) ?? '—']) }}</span>
            </div>

            @error('changeRequest')
                <p class="flex items-start gap-2 rounded-lg border border-status-critical bg-status-critical-soft px-3 py-2 text-sm font-semibold text-status-critical" role="alert" data-test="change-request-error">
                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ $message }}</span>
                </p>
            @enderror

            <section class="min-w-0">
                <h3 class="type-label text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.reason_heading') }}</h3>
                <p class="mt-1 text-sm whitespace-pre-line text-secondary-dark dark:text-ink" dir="auto">{{ $deciding->reason }}</p>
            </section>

            <section class="min-w-0">
                <h3 class="type-label text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.changes_heading') }}</h3>
                <x-proposals.change-list :lines="$this->decidingChangeLines" class="mt-1" />
                @if ($addsPlace)
                    <p class="mt-2 flex items-start gap-2 rounded-md bg-status-warn-soft px-2 py-1.5 text-sm text-status-warn" role="note" data-test="change-new-place-note">
                        <flux:icon.information-circle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {{ __('proposals.change.new_place_note') }}
                    </p>
                @endif
            </section>

            <section class="min-w-0">
                <h3 class="type-label text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.reviews_heading') }}</h3>
                <div class="mt-1">
                    @include('pages.proposals._change-request-reviews', ['changeRequest' => $deciding])
                </div>
            </section>

            @if (! $deciding->isPending())
                <section class="min-w-0" data-change-decision>
                    <h3 class="type-label text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.decision_heading') }}</h3>
                    <p class="mt-1 text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('proposals.change.decided_by', ['name' => $deciding->decider?->name ?? '—', 'date' => $deciding->decided_at?->translatedFormat(__('formats.date')) ?? '—']) }}</p>
                    @if (filled($deciding->decision_note))
                        <p class="mt-1 text-sm whitespace-pre-line text-secondary-dark dark:text-ink" dir="auto">{{ $deciding->decision_note }}</p>
                    @endif
                </section>
            @endif

            @if ($reviewSlot !== null)
                <section class="grid min-w-0 gap-3 border-t border-secondary-soft pt-4 dark:border-line" data-test="change-review-form">
                    <x-ui.form-segmented
                        wire:model.live="reviewVerdict"
                        :label="__('proposals.change.your_review', ['slot' => __('proposals.change.slot.'.$reviewSlot)])"
                        required
                        :help="__('proposals.change.review_changeable')"
                        :options="[
                            ['value' => \App\Enums\ChangeReviewVerdict::NoObjection->value, 'label' => __('proposals.change.no_objection'), 'icon' => 'check-circle', 'test' => 'verdict-no-objection'],
                            ['value' => \App\Enums\ChangeReviewVerdict::Remarks->value, 'label' => __('proposals.change.have_remarks'), 'icon' => 'chat-bubble-left-ellipsis', 'test' => 'verdict-remarks'],
                        ]"
                    />
                    @if ($reviewVerdict === \App\Enums\ChangeReviewVerdict::Remarks->value)
                        <x-ui.form-textarea wire:model="reviewNote" :label="__('proposals.change.remarks_label')" rows="3" required dir="auto" data-test="change-review-note" />
                    @endif
                    <div>
                        <x-ui.button type="button" tone="primary" size="sm" icon="check" class="min-h-10 touch-manipulation" wire:click="submitChangeReview('{{ $reviewSlot }}')" wire:loading.attr="disabled" wire:target="submitChangeReview" data-test="save-change-review">{{ __('proposals.change.review_save') }}</x-ui.button>
                    </div>
                </section>
            @endif

            @if ($canDecide)
                <section class="grid min-w-0 gap-3 border-t border-secondary-soft pt-4 dark:border-line" data-test="change-decision-form">
                    <h3 class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('proposals.change.decision_heading') }}</h3>

                    @if ($changeIsStale)
                        <p class="flex items-start gap-2 rounded-lg border border-status-critical bg-status-critical-soft px-3 py-2 text-sm font-semibold text-status-critical" role="alert" data-test="change-stale">
                            <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>{{ __('proposals.change.stale_only_reject') }}</span>
                        </p>
                    @else
                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.button type="button" tone="primary" size="sm" icon="check-badge" class="min-h-10 touch-manipulation" wire:click="confirmApproveChange" wire:loading.attr="disabled" wire:target="confirmApproveChange,approveChange" :disabled="$waitingReviews !== []" data-test="approve-change">{{ __('proposals.change.approve') }}</x-ui.button>
                            @if ($waitingReviews !== [])
                                <p class="min-w-0 flex-1 text-sm text-secondary-medium dark:text-ink-muted" data-test="approve-blocked">{{ __('proposals.change.approve_blocked', ['waiting' => implode(', ', $waitingReviews)]) }}</p>
                            @endif
                        </div>
                    @endif

                    <x-ui.form-textarea wire:model="rejectNote" :label="__('proposals.change.reject_note_label')" rows="2" dir="auto" data-test="change-reject-note" />
                    <div>
                        <x-ui.button type="button" size="sm" icon="x-circle" class="min-h-10 touch-manipulation" wire:click="rejectChange" wire:loading.attr="disabled" wire:target="rejectChange" data-test="reject-change">{{ __('proposals.change.reject_send') }}</x-ui.button>
                    </div>
                </section>
            @endif

            @if ($isRequester)
                <section class="min-w-0 border-t border-secondary-soft pt-4 dark:border-line">
                    <x-ui.button type="button" size="sm" icon="arrow-uturn-left" class="min-h-10 touch-manipulation" wire:click="confirmWithdrawChange" data-test="withdraw-change">{{ __('proposals.change.withdraw') }}</x-ui.button>
                </section>
            @endif

            @if ($deciding->isPending() && $reviewSlot === null && ! $canDecide && ! $isRequester)
                <p class="border-t border-secondary-soft pt-4 text-sm text-secondary-medium dark:border-line dark:text-ink-muted" data-test="change-read-only">{{ __('proposals.change.read_only') }}</p>
            @endif
        </div>
    @endif
</x-ui.modal>

<x-ui.confirm-modal
    icon="check-badge"
    wire:model="showApproveChangeModal"
    tone="neutral"
    :title="__('proposals.change.approve_confirm_title')"
    :message="__('proposals.change.approve_confirm_body')"
    confirm="approveChange"
    :confirm-label="__('proposals.change.approve')"
    confirm-test="confirm-approve-change"
    data-test="approve-change-modal"
/>

<x-ui.confirm-modal
    icon="arrow-uturn-left"
    wire:model="showWithdrawChangeModal"
    tone="neutral"
    :title="__('proposals.change.withdraw_confirm_title')"
    :message="__('proposals.change.withdraw_confirm_body')"
    confirm="withdrawChange"
    :confirm-label="__('proposals.change.withdraw')"
    confirm-test="confirm-withdraw-change"
    data-test="withdraw-change-modal"
/>
