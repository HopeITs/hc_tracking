<?php

use App\Enums\MonthlyReportStatus;
use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportActivities;
use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportLines;
use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportRisks;
use App\Livewire\Concerns\MonthlyReports\ManagesMonthlyReportFiles;
use App\Livewire\Concerns\MonthlyReports\ReadsMonthlyReport;
use App\Livewire\Concerns\MonthlyReports\ReadsMonthlyReportContext;
use App\Livewire\Concerns\MonthlyReports\RunsMonthlyReportWorkflow;
use App\Models\MonthlyReport;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.monthly_report')] class extends Component
{
    use EditsMonthlyReportActivities;
    use EditsMonthlyReportLines;
    use EditsMonthlyReportRisks;
    use ManagesMonthlyReportFiles;
    use ReadsMonthlyReport;
    use ReadsMonthlyReportContext;
    use RunsMonthlyReportWorkflow;

    public function mount(MonthlyReport $monthlyReport): void
    {
        Gate::authorize('view', $monthlyReport);

        $this->monthlyReportId = $monthlyReport->id;
        $this->renderedStatus = $monthlyReport->status->value;
        $this->seedFigures();
        $this->seedNarrative();
        $this->seedRisks();
    }

    /**
     * The submitted report after this one in the validator's queue, in the
     * order "To validate" lists it (oldest submission first), wrapping to the
     * start, so a validator walks the queue without going back to the list
     * and a skipped report comes round again last. Null for anyone with no
     * queue, or an empty one.
     */
    #[Computed]
    public function nextToValidate(): ?MonthlyReport
    {
        $user = auth()->user();

        if (! $user->can('reports.approve') || $user->hasRole('General Manager')) {
            return null;
        }

        $queue = fn () => MonthlyReport::query()
            ->validatableBy($user)
            ->where('status', MonthlyReportStatus::Submitted)
            ->whereKeyNot($this->monthlyReportId)
            ->orderBy('submitted_at')
            ->orderBy('id');
        $columns = ['id', 'slug', 'report_template_id', 'period'];
        $current = MonthlyReport::query()->whereKey($this->monthlyReportId)->first(['id', 'submitted_at']);

        // Forward from this report in the queue's order, then back to its start: skipping a report never bounces between two.
        $after = $current?->submitted_at === null ? null : $queue()
            ->where(fn ($later) => $later->where('submitted_at', '>', $current->submitted_at)
                ->orWhere(fn ($tie) => $tie->where('submitted_at', $current->submitted_at)->where('id', '>', $current->id)))
            ->first($columns);

        return $after ?? $queue()->first($columns);
    }
};
?>

@php
    $report = $this->report;
    $abilities = $this->abilities;
    $isEditable = collect($this->rows)->contains(fn (array $row): bool => $row['editable']);
    $monthIsEmpty = $this->rows === [] && $this->activities === [] && $this->questions->isEmpty() && $this->answeredQuestions->isEmpty();
@endphp

<section class="@container/detail mrp-page flex w-full flex-1 flex-col gap-6 text-secondary-dark dark:text-ink">
    @include('pages.monthly-reports._show-header')

    @include('pages.monthly-reports._show-journey')

    @include('pages.monthly-reports._show-workflow')

    <div class="mrp-cols">
        <div class="mrp-main">
            @include('pages.monthly-reports._show-progress')

            @if ($monthIsEmpty)
                {{-- A month with no indicator, activity or question says so once, instead of three empty sections a screen tall each. --}}
                <section aria-labelledby="monthly-report-empty-heading" class="mrp-card flex items-start gap-3" data-empty-month>
                    <flux:icon.inbox variant="outline" class="mt-0.5 size-5 shrink-0 text-secondary-medium dark:text-ink-muted" aria-hidden="true" />
                    <div class="flex flex-col gap-1">
                        <h2 id="monthly-report-empty-heading" class="text-base font-bold">{{ __('monthly_reports.nothing_this_month') }}</h2>
                        <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.nothing_this_month_body') }}</p>
                        {{-- "Can still be submitted" is advice for whoever submits it; to a validator, or once it is submitted, it only confuses. --}}
                        @if ($abilities['submit'])
                            <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.nothing_this_month_submit') }}</p>
                        @endif
                    </div>
                </section>
            @else
                <section aria-labelledby="monthly-report-lines-heading" class="flex flex-col gap-3" data-monthly-report-grid>
                    <div class="mrp-section-head">
                        <h2 id="monthly-report-lines-heading">{{ __('monthly_reports.indicators_this_month') }}</h2>
                        <p>
                            @if ($isEditable)
                                {{ __('monthly_reports.grid_help') }}
                            @elseif ($report->status === \App\Enums\MonthlyReportStatus::Validated)
                                {{ __('monthly_reports.read_only_validated') }}
                            @elseif ($report->status !== \App\Enums\MonthlyReportStatus::Draft)
                                {{ __('monthly_reports.read_only_submitted') }}
                            @else
                                {{ __('monthly_reports.read_only_viewer') }}
                            @endif
                        </p>
                    </div>

                    @if ($this->rows === [])
                        @include('pages.monthly-reports._show-empty-line', ['key' => 'lines', 'icon' => 'chart-bar', 'heading' => __('monthly_reports.no_lines'), 'body' => $abilities['submit'] ? __('monthly_reports.no_lines_body') : null])
                    @else
                        <x-monthly-reports.lines-grid :rows="$this->rows" :figures="$figures" :context="$this->lineContext" :month="$report->period" />
                    @endif
                </section>

                @include('pages.monthly-reports._show-activities')

                @include('pages.monthly-reports._show-answers')
            @endif

            @include('pages.monthly-reports._show-risks')
        </div>

        @include('pages.monthly-reports._show-aside')
    </div>

    @include('pages.monthly-reports._show-actionbar')

    @include('pages.monthly-reports._show-modals')

    @include('pages.monthly-reports._show-file-modals')
</section>
