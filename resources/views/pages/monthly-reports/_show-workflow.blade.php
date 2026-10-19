{{-- The workflow strip of ⚡show.blade.php: the actions this viewer may take now, why a blocked one is blocked, and the reopen request history. --}}
@use('App\Enums\MonthlyReportStatus')
@use('App\Enums\ReopenRequestStatus')

@php
    $submitBlocker = $this->submitBlocker;
    $validateBlocked = $this->pendingMealLines > 0;
    $hasPendingRequest = $report->reopenRequests->contains('status', ReopenRequestStatus::Pending);
    $showsActions = $abilities['submit'] || $abilities['validate'] || $abilities['return'] || $abilities['update']
        || ($abilities['requestReopen'] && ! $hasPendingRequest) || $this->decidableRequest !== null
        || ($abilities['markSeen'] && $report->seen_by_meal_at === null) || $abilities['delete'];
@endphp

@if ($showsActions)
    <div class="flex flex-col gap-3 border-y border-secondary-soft py-4 dark:border-line">
        <div class="flex flex-wrap items-center gap-2">
            @if ($abilities['submit'])
                <span data-monthly-report-submit>
                    <x-ui.button
                        tone="primary"
                        icon="paper-airplane"
                        class="rtl:[&_svg]:-scale-x-100"
                        wire:click="openSubmitModal"
                        wire:loading.attr="disabled"
                        wire:target="figures,openSubmitModal"
                        :disabled="$submitBlocker !== null"
                        data-test="submit-button"
                    >{{ __('monthly_reports.submit') }}</x-ui.button>
                </span>
            @endif

            @if ($abilities['validate'])
                <span data-monthly-report-validate>
                    <x-ui.button
                        tone="primary"
                        icon="check-badge"
                        wire:click="openValidateModal"
                        wire:loading.attr="disabled"
                        wire:target="figures,openValidateModal"
                        :disabled="$validateBlocked"
                        data-test="validate-button"
                    >{{ __('monthly_reports.validate') }}</x-ui.button>
                </span>
            @endif

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

        @if ($abilities['submit'] && $submitBlocker !== null)
            <p class="flex items-center gap-1.5 text-sm text-secondary-medium dark:text-ink-muted" data-submit-blocked>
                <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                {{ $submitBlocker }}
            </p>
        @endif

        @if ($abilities['validate'] && $validateBlocked)
            <p class="flex items-center gap-1.5 text-sm text-secondary-medium dark:text-ink-muted" data-validate-blocked>
                <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                {{ __('monthly_reports.meal_lines_pending') }}
            </p>
        @endif

        @error('workflow')
            <p class="text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror
    </div>
@endif

@if ($report->reopenRequests->isNotEmpty())
    <section aria-labelledby="reopen-requests-heading" class="flex flex-col gap-2">
        <h2 id="reopen-requests-heading" class="text-base font-bold">{{ __('monthly_reports.reopen_requests') }}</h2>
        <ol class="flex flex-col divide-y divide-secondary-soft dark:divide-line" role="list">
            @foreach ($report->reopenRequests as $reopenRequest)
                <li class="flex flex-col gap-1 py-3" data-reopen-request="{{ $reopenRequest->id }}" wire:key="reopen-request-{{ $reopenRequest->id }}">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-bold">{{ $reopenRequest->requester?->name }}</span>
                        <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ $reopenRequest->created_at?->translatedFormat(__('formats.date')) }}</span>
                        <span @class([
                            'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-semibold',
                            'border-status-warn/30 bg-status-warn-soft text-status-warn' => $reopenRequest->status === ReopenRequestStatus::Pending,
                            'border-status-good/30 bg-status-good-soft text-status-good' => $reopenRequest->status === ReopenRequestStatus::Granted,
                            'border-secondary-light text-secondary-medium dark:border-line dark:text-ink-muted' => $reopenRequest->status === ReopenRequestStatus::Denied,
                        ])>{{ $reopenRequest->status->label() }}</span>
                    </p>
                    <p class="max-w-3xl text-sm whitespace-pre-line text-secondary-medium dark:text-ink-muted" dir="auto">{{ $reopenRequest->reason }}</p>
                    @if ($reopenRequest->decided_at !== null)
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">
                            {{ __('monthly_reports.decided_by_on', ['name' => $reopenRequest->decider?->name ?? '—', 'date' => $reopenRequest->decided_at->translatedFormat(__('formats.date'))]) }}
                            @if (filled($reopenRequest->decision_note))
                                · <span dir="auto">{{ $reopenRequest->decision_note }}</span>
                            @endif
                        </p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
