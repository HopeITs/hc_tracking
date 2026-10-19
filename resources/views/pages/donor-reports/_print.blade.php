{{--
    The print view of a saved donor report (donor-reports.print): the frozen
    snapshot only, every center row expanded, finance for money holders only.
--}}
<x-layouts::print :title="$report->title">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-secondary-soft pb-4 dark:border-line">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold" dir="auto">{{ $report->title }}</h1>
            <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">
                {{ $report->type->label() }} · {{ $report->audience->label() }} · {{ __('donor_reports.version_number', ['version' => $report->version]) }}
                · {{ __('donor_reports.generated_at', ['date' => \Carbon\CarbonImmutable::parse($report->snapshot['generated_at'] ?? $report->created_at)->translatedFormat(__('formats.date'))]) }}
            </p>
        </div>
        <button type="button" onclick="window.print()" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white print:hidden" data-test="print-donor-report">
            {{ __('donor_reports.print') }}
        </button>
    </div>

    <x-donor-reports.snapshot :snapshot="$report->snapshot" :show-finance="$showFinance" print />
</x-layouts::print>
