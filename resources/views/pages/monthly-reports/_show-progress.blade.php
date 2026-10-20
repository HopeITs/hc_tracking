{{-- How far the viewer's part of the month is filled (ReadsMonthlyReport::progress()) and what is still needed. --}}
@php
    $progress = $this->progress;
    $needed = $progress['needed'] ?? [];
    $lastNeeded = array_pop($needed);
    $neededText = $needed === [] ? $lastNeeded : implode(__('monthly_reports.list_comma'), $needed).__('monthly_reports.list_and').$lastNeeded;
@endphp

@if ($progress !== null && $progress['total'] > 0)
    <section aria-labelledby="monthly-report-progress-heading" class="flex flex-col gap-2" data-monthly-report-progress>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="monthly-report-progress-heading" class="text-sm font-semibold">{{ __('monthly_reports.progress_label') }}</h2>
            <span class="text-sm tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.progress_done', ['done' => $progress['done'], 'total' => $progress['total']]) }}</span>
        </div>
        <div
            role="progressbar"
            aria-labelledby="monthly-report-progress-heading"
            aria-valuemin="0"
            aria-valuemax="{{ $progress['total'] }}"
            aria-valuenow="{{ $progress['done'] }}"
            class="h-1.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
        >
            <div class="h-full rounded-full bg-primary transition-[width] dark:bg-brand-text" style="width: {{ $progress['percent'] }}%"></div>
        </div>
        @if ($progress['needed'] !== [])
            <p class="flex items-center gap-1.5 text-sm text-status-warn" data-still-needed>
                <flux:icon.exclamation-circle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                {{ __('monthly_reports.still_needed', ['items' => $neededText]) }}
            </p>
        @else
            <p class="flex items-center gap-1.5 text-sm text-status-good">
                <flux:icon.check-circle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                {{ __('monthly_reports.all_done') }}
            </p>
        @endif
    </section>
@endif
