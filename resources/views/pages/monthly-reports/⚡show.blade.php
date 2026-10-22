<?php

use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportActivities;
use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportLines;
use App\Livewire\Concerns\MonthlyReports\ManagesMonthlyReportFiles;
use App\Livewire\Concerns\MonthlyReports\ReadsMonthlyReport;
use App\Livewire\Concerns\MonthlyReports\RunsMonthlyReportWorkflow;
use App\Models\MonthlyReport;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.monthly_report')] class extends Component {
    use EditsMonthlyReportActivities;
    use EditsMonthlyReportLines;
    use ManagesMonthlyReportFiles;
    use ReadsMonthlyReport;
    use RunsMonthlyReportWorkflow;

    public function mount(MonthlyReport $monthlyReport): void
    {
        Gate::authorize('view', $monthlyReport);

        $this->monthlyReportId = $monthlyReport->id;
        $this->renderedStatus = $monthlyReport->status->value;
        $this->seedFigures();
        $this->seedNarrative();
    }
};
?>

@php
    $report = $this->report;
    $abilities = $this->abilities;
    $isEditable = collect($this->rows)->contains(fn (array $row): bool => $row['editable']);
@endphp

<x-desk.record-sheet register="pages::monthly-reports.index" :close-url="route('monthly-reports.index')" :title="$report->period->translatedFormat('F Y')" :subtitle="$report->project->name">
<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    @include('pages.monthly-reports._show-header')

    @include('pages.monthly-reports._show-progress')

    @include('pages.monthly-reports._show-workflow')

    <section aria-labelledby="monthly-report-lines-heading" class="flex flex-col gap-3" data-monthly-report-grid>
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

        @if ($this->rows === [])
            <x-ui.empty-state icon="chart-bar" :heading="__('monthly_reports.no_lines')" :body="__('monthly_reports.no_lines_body')" />
        @else
            <x-monthly-reports.lines-grid :rows="$this->rows" :figures="$figures" />
        @endif
    </section>

    @include('pages.monthly-reports._show-activities')

    @include('pages.monthly-reports._show-answers')

    @include('pages.monthly-reports._show-actionbar')

    @include('pages.monthly-reports._show-modals')

    @include('pages.monthly-reports._show-file-modals')
</section>
</x-desk.record-sheet>
