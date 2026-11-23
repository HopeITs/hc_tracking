{{-- The workflow strip of ⚡show.blade.php: the secondary actions this viewer may take now. Submit and Validate live in _show-actionbar; the reopen requests sit in _show-aside. --}}
@use('App\Enums\MonthlyReportStatus')
@use('App\Enums\ReopenRequestStatus')

@php
    $hasPendingRequest = $report->reopenRequests->contains('status', ReopenRequestStatus::Pending);
    $showsActions = $abilities['return'] || $abilities['update']
        || ($abilities['requestReopen'] && ! $hasPendingRequest) || $this->decidableRequest !== null
        || ($abilities['markSeen'] && $report->seen_by_meal_at === null) || $abilities['delete'];
@endphp

@if ($showsActions)
    <div class="mrp-actions flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @if ($abilities['return'])
                <x-ui.button icon="arrow-uturn-left" class="rtl:[&_svg]:-scale-x-100" wire:click="openReturnModal" data-test="return-button">{{ __('monthly_reports.return') }}</x-ui.button>
            @endif

            @if ($abilities['requestReopen'] && ! $hasPendingRequest)
                <x-ui.button icon="lock-open" wire:click="openReopenModal" data-test="reopen-button">{{ __('monthly_reports.request_reopen') }}</x-ui.button>
            @endif

            @if ($this->decidableRequest !== null)
                <x-ui.button tone="primary" icon="scale" wire:click="openDecisionModal({{ $this->decidableRequest->id }})" data-test="decide-button">{{ __('monthly_reports.decide_reopen') }}</x-ui.button>
            @endif

            @if ($abilities['markSeen'] && $report->seen_by_meal_at === null)
                <x-ui.button icon="eye" wire:click="markSeen" wire:loading.attr="disabled" wire:target="markSeen" data-test="mark-seen-button">{{ __('monthly_reports.mark_seen') }}</x-ui.button>
            @endif

            @if ($abilities['update'])
                <x-ui.button icon="arrow-path" wire:click="refreshLines" wire:loading.attr="disabled" wire:target="refreshLines" data-test="refresh-lines-button">{{ __('monthly_reports.refresh_lines') }}</x-ui.button>
            @endif

            @if ($abilities['delete'])
                <x-ui.button tone="ghost" icon="trash" class="ms-auto" wire:click="openDeleteModal" data-test="delete-button">{{ __('monthly_reports.delete_draft') }}</x-ui.button>
            @endif
        </div>

        @error('workflow')
            <p class="text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    </div>
@endif
