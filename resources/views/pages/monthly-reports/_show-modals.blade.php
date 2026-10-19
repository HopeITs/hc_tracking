{{-- The confirmations of ⚡show.blade.php, each bound to its wire:model boolean (never :open). --}}
<x-ui.modal wire:model="showSubmitModal" size="sm" :title="__('monthly_reports.submit_title')" :description="__('monthly_reports.submit_message')">
    @if ($showSubmitModal)
        @if ($this->incompleteLines !== [])
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold text-status-critical">{{ trans_choice('monthly_reports.incomplete_lines', count($this->incompleteLines), ['count' => count($this->incompleteLines)]) }}</p>
                <ul class="flex flex-col gap-1 text-sm" role="list">
                    @foreach ($this->incompleteLines as $incomplete)
                        <li class="flex gap-2" data-incomplete-line="{{ $incomplete['id'] }}" wire:key="incomplete-{{ $incomplete['id'] }}">
                            <bdi dir="ltr" class="shrink-0 font-mono text-xs text-secondary-medium dark:text-ink-muted">{{ $incomplete['code'] }}</bdi>
                            <span dir="auto">{{ $incomplete['name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.all_lines_filled') }}</p>
        @endif

        @error('workflow')
            <p class="mt-3 text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    @endif

    <x-slot:actions>
        <x-ui.button type="button" tone="primary" wire:click="submitReport" wire:loading.attr="disabled" wire:target="submitReport,figures" data-test="confirm-submit">{{ __('monthly_reports.submit') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showValidateModal" size="sm" :title="__('monthly_reports.validate_title')" :description="__('monthly_reports.validate_message')">
    @if ($showValidateModal)
        @error('workflow')
            <p class="text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    @endif

    <x-slot:actions>
        <x-ui.button type="button" tone="primary" wire:click="validateReport" wire:loading.attr="disabled" wire:target="validateReport,figures" data-test="confirm-validate">{{ __('monthly_reports.validate') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showReturnModal" size="sm" :title="__('monthly_reports.return_title')" :description="__('monthly_reports.return_message')" submit="returnReport" required-note>
    @if ($showReturnModal)
        <x-ui.form-textarea :label="__('monthly_reports.return_reason')" wire:model="returnReason" required :help="__('monthly_reports.reason_help')" />
        @error('workflow')
            <p class="mt-3 text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="confirm-return">{{ __('monthly_reports.return') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showReopenModal" size="sm" :title="__('monthly_reports.reopen_title')" :description="__('monthly_reports.reopen_message')" submit="requestReopen" required-note>
    @if ($showReopenModal)
        <x-ui.form-textarea :label="__('monthly_reports.reopen_reason')" wire:model="reopenReason" required :help="__('monthly_reports.reason_help')" />
        @error('workflow')
            <p class="mt-3 text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="confirm-reopen">{{ __('monthly_reports.request_reopen') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showDecisionModal" size="sm" :title="__('monthly_reports.decision_title')" :description="__('monthly_reports.decision_message')">
    @if ($showDecisionModal)
        @php
            $decidingRequest = $report->reopenRequests->firstWhere('id', $decidingRequestId);
        @endphp
        @if ($decidingRequest !== null)
            <div class="mb-4 border-s-2 border-secondary-light ps-3 dark:border-line-strong">
                <p class="text-sm font-bold">{{ $decidingRequest->requester?->name }}</p>
                <p class="mt-1 text-sm whitespace-pre-line text-secondary-medium dark:text-ink-muted" dir="auto">{{ $decidingRequest->reason }}</p>
            </div>
        @endif
        <x-ui.form-textarea :label="__('monthly_reports.decision_note')" wire:model="decisionNote" :help="__('monthly_reports.decision_note_help')" />
        @error('workflow')
            <p class="mt-3 text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    @endif

    <x-slot:actions>
        <x-ui.button type="button" wire:click="decideReopen(false)" wire:loading.attr="disabled" wire:target="decideReopen" data-test="confirm-deny">{{ __('monthly_reports.deny') }}</x-ui.button>
        <x-ui.button type="button" tone="primary" wire:click="decideReopen(true)" wire:loading.attr="disabled" wire:target="decideReopen" data-test="confirm-grant">{{ __('monthly_reports.grant') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    wire:model="showDeleteModal"
    :title="__('monthly_reports.delete_title')"
    :message="__('monthly_reports.delete_message')"
    confirm="deleteReport"
    :confirm-label="__('monthly_reports.delete_draft')"
    confirm-test="confirm-delete"
/>
