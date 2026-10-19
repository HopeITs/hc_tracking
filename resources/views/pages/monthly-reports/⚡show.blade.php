<?php

use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportLines;
use App\Livewire\Concerns\MonthlyReports\ReadsMonthlyReport;
use App\Livewire\Concerns\MonthlyReports\RunsMonthlyReportWorkflow;
use App\Models\MonthlyReport;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.monthly_report')] class extends Component {
    use EditsMonthlyReportLines;
    use ReadsMonthlyReport;
    use RunsMonthlyReportWorkflow;

    public function mount(MonthlyReport $monthlyReport): void
    {
        Gate::authorize('view', $monthlyReport);

        $this->monthlyReportId = $monthlyReport->id;
        $this->renderedStatus = $monthlyReport->status->value;
        $this->seedFigures();
    }
};
?>

@php
    $report = $this->report;
    $abilities = $this->abilities;
    $isEditable = collect($this->rows)->contains(fn (array $row): bool => $row['editable']);
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    @include('pages.monthly-reports._show-header')

    @include('pages.monthly-reports._show-workflow')

    <section aria-labelledby="monthly-report-lines-heading" class="flex flex-col gap-3" data-monthly-report-grid>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="monthly-report-lines-heading" class="text-base font-bold">{{ __('monthly_reports.indicators_this_month') }}</h2>
                <p class="type-meta text-secondary-medium dark:text-ink-muted">
                    @if ($isEditable)
                        {{ __('monthly_reports.grid_help') }}
                    @elseif ($report->status !== \App\Enums\MonthlyReportStatus::Draft)
                        {{ __('monthly_reports.read_only_submitted') }}
                    @else
                        {{ __('monthly_reports.read_only_viewer') }}
                    @endif
                </p>
            </div>
            <p class="type-meta text-secondary-medium dark:text-ink-muted" aria-live="polite">
                <span wire:loading.delay wire:target="figures">{{ __('monthly_reports.saving') }}</span>
                <span wire:loading.remove wire:target="figures">@if ($isEditable){{ __('monthly_reports.saved') }}@endif</span>
            </p>
        </div>

        @if ($this->rows === [])
            <x-ui.empty-state icon="chart-bar" :heading="__('monthly_reports.no_lines')" :body="__('monthly_reports.no_lines_body')" />
        @else
            <x-monthly-reports.lines-grid :rows="$this->rows" :figures="$figures" />
        @endif
    </section>

    @include('pages.monthly-reports._show-modals')
</section>
