<?php

use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Livewire\Concerns\ManagesReportForm;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Reports\ManagesReportFiles;
use App\Livewire\Concerns\Reports\RunsReportWorkflow;
use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::desk'), Title('titles.report')] class extends Component {
    use ManagesReportFiles;
    use ManagesReportForm;
    use PreviewsFiles;
    use RunsReportWorkflow;
    use WithFileUploads;

    #[Locked]
    public int $reportId;

    public function mount(Report $report): void
    {
        $this->reportId = $report->id;
    }

    #[Computed]
    public function report(): Report
    {
        return Report::query()
            ->select([
                'id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'period_start', 'period_end',
                'value', 'narrative_report_notes', 'key_achievements', 'challenges',
                'lessons_learned', 'next_period_plans', 'submit_date', 'is_seen_by_meal', 'monthly_report_id',
            ])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,result_id,measure,unit',
                'planIndicator.indicator.result:id,statement,type',
                'planIndicator.project:id,name',
                'planIndicator.source',
                // Every figure on the target, for its achievement by the shared rule.
                'planIndicator.reports:id,plan_indicator_id,status,planned_date,value',
                'user:id,hr_user_id',
                'monthlyReport:id,slug',
            ])
            ->findOrFail($this->reportId);
    }

    protected function reportSaved(Report $report, bool $isCreate): void
    {
        unset($this->report, $this->history, $this->achievement, $this->achievementTone);
    }

    protected function reportDeleted(): void
    {
        $this->redirectRoute('reports.index', navigate: true);
    }

    /**
     * How far the target this report belongs to has been achieved.
     *
     * The target's own figure by the one shared rule (PlanIndicator::
     * achievedValue(): approved reports only, numeric summed, percentage the
     * latest level) — the same number the targets, indicators and results
     * pages show. One quarterly figure divided by an annual target is not
     * achievement. Null when the target is zero.
     */
    #[Computed]
    public function achievement(): ?int
    {
        $percent = $this->report->planIndicator->percentAchieved();

        return $percent === null ? null : (int) floor($percent);
    }

    /**
     * The tone the achievement figure renders in, shared by the progress ring
     * and anything else that needs to agree on what "good" looks like here.
     *
     * A badly missed target is 'critical', not 'warn': this app's own tone
     * vocabulary already reserves the brand red for exactly this signal
     * (see ui/stat-tile, ui/progress-ring), and a missed target is the one
     * thing on this page a projects/fundraising manager most needs to see
     * at a glance.
     */
    #[Computed]
    public function achievementTone(): string
    {
        return match (true) {
            $this->achievement === null => 'neutral',
            $this->achievement >= 90 => 'good',
            $this->achievement < 50 => 'critical',
            default => 'neutral',
        };
    }

    /**
     * The report's files, in the order the page lists them.
     *
     * @return Collection<int, ReportFile>
     */
    protected function previewableFiles(): Collection
    {
        return $this->files;
    }

    /**
     * Viewing is not an edit, so it stays available on a locked report. An id
     * that is not one of this report's files is a 404.
     */
    protected function previewableFileId(int $fileId): ?int
    {
        return $this->reportFile($fileId)->id;
    }
};
?>

@php
    $report = $this->report;
    $planIndicator = $report->planIndicator;

    $sourceValue = match (true) {
        $planIndicator->source === null => __('reports.source_no_longer_exists'),
        $planIndicator->source_type === 'annual_plan' => __('reports.committed_in_an_annual_plan'),
        default => $planIndicator->source->name ?? __('reports.committed_in_a_proposal'),
    };

    $sourceHref = match (true) {
        $planIndicator->source === null => null,
        $planIndicator->source_type === 'annual_plan' => route('annual-plans.show', $planIndicator->source),
        $planIndicator->source_type !== 'annual_plan' && auth()->user()->can('proposals.view') => route('proposals.show', $planIndicator->source),
        default => null,
    };

    $unit = $planIndicator->indicator->measure === IndicatorMeasure::Percentage ? '%' : '';
    $formatFigure = fn (float $value): string => number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);

    // Every node the reader can act on carries a real href: no dead-end labels
    // dressed up as links. The chain reads the way the workflow runs — result,
    // indicator, target — then who delivers it and where it was committed.
    $chain = [
        [
            'label' => $planIndicator->indicator->result->type->label(),
            'value' => Str::limit($planIndicator->indicator->result->statement, 64),
            'href' => route('results.index', ['search' => Str::limit($planIndicator->indicator->result->statement, 60, '')]),
        ],
        [
            'label' => __('common.indicator_name'),
            'value' => $planIndicator->indicator->name,
            'href' => route('indicators.index', ['inspect' => $planIndicator->indicator_id]),
        ],
        [
            'label' => __('reports.target'),
            'value' => $formatFigure((float) $planIndicator->target).$unit.' · '.$planIndicator->period->label(),
            'href' => route('targets.index', ['indicator' => $planIndicator->indicator_id]),
        ],
        [
            'label' => __('common.project'),
            'value' => $planIndicator->project?->name ?? __('common.organisation_wide'),
            'href' => $planIndicator->project !== null ? route('projects.show', $planIndicator->project) : null,
        ],
        ['label' => __('reports.committed_via'), 'value' => $sourceValue, 'href' => $sourceHref],
    ];
@endphp

<x-desk.record-sheet register="pages::reports.index" :close-url="route('reports.index')" :title="$planIndicator->indicator->name">
<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.reports'), 'url' => route('reports.index')],
            ['label' => $planIndicator->indicator->name],
        ]"
        :title="$planIndicator->indicator->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'report', $this->reportId)"
        :subtitle="$planIndicator->indicator->result->statement"
        title-class="text-3xl! font-extrabold! leading-none! tracking-tight!"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ __('reports.period_to_date', ['date' => $report->planned_date?->translatedFormat(__('formats.date'))]) }}</span>
            @if ($planIndicator->project !== null)
                <a href="{{ route('projects.show', $planIndicator->project) }}" wire:navigate class="hover:underline">{{ $planIndicator->project->name }}</a>
            @else
                <span>{{ __('common.organisation_wide') }}</span>
            @endif
            <span>
                @if ($planIndicator->source === null)
                    {{ __('reports.source_no_longer_exists') }}
                @elseif ($planIndicator->source_type === 'annual_plan')
                    @if ($sourceHref !== null)
                        <a href="{{ $sourceHref }}" wire:navigate class="hover:underline">{{ __('reports.committed_in_an_annual_plan') }}</a>
                    @else
                        {{ __('reports.committed_in_an_annual_plan') }}
                    @endif
                @else
                    {{ __('reports.committed_in_a_proposal_2') }}
                    {{-- The proposal's name stays for everyone; only its page is gated. --}}
                    @if ($sourceHref !== null)
                        <a href="{{ $sourceHref }}" wire:navigate class="hover:underline">{{ $planIndicator->source->name }}</a>
                    @else
                        {{ $planIndicator->source->name }}
                    @endif
                @endif
            </span>
            @if ($report->monthlyReport !== null)
                {{-- A mirror is changed only on its monthly report (contracts §13). --}}
                <a href="{{ route('monthly-reports.show', $report->monthlyReport) }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold hover:bg-secondary-soft dark:border-line-strong dark:hover:bg-white/[0.06]" data-monthly-report-chip="{{ $report->id }}">
                    <flux:icon.calendar-days variant="micro" class="size-3" aria-hidden="true" />
                    {{ __('reports.monthly_report_chip') }}
                </a>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$report->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-line" />
            @if ($planIndicator->source !== null && auth()->user()->can('create', App\Models\Report::class))
                <x-ui.button icon="document-plus" wire:click="openCreateModal({{ $planIndicator->id }})" data-test="record-next-report-button">{{ __('reports.record_next_report') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.detail-header>

    {{-- The hero: this figure, the target it counts toward, and how far that target has come. --}}
    <div class="flex flex-col gap-8 border-y border-secondary-soft py-8 dark:border-line @lg/detail:flex-row @lg/detail:items-center">
        <div class="flex shrink-0 items-center gap-4">
            <x-ui.progress-ring :value="$this->achievement" size="lg" :tone="$this->achievementTone" :label="__('reports.target_achieved')" />
            <div class="@lg/detail:hidden">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.target_achieved') }}</p>
                <p class="mt-1 type-meta text-secondary-medium dark:text-ink-muted">{{ __('reports.approved_reports_only') }}</p>
            </div>
        </div>

        <div class="flex flex-1 flex-wrap items-stretch gap-y-6">
            <div class="min-w-32 flex-1">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.this_report') }}</p>
                <p class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums">{{ $formatFigure((float) $report->value) }}{{ $unit }}</p>
                <p class="mt-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                    {{ $report->status === ReportStatus::Approved ? __('reports.counted_toward_the_target') : __('reports.counts_toward_the_target_once_approved') }}
                </p>
            </div>
            <div class="min-w-32 flex-1 border-s border-secondary-soft ps-8 dark:border-line">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.achieved_so_far') }}</p>
                <p class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums">{{ $formatFigure($planIndicator->achievedValue()) }}{{ $unit }}</p>
                <p class="mt-1.5 type-meta text-secondary-medium dark:text-ink-muted">{{ __('reports.approved_reports_only') }}</p>
            </div>
            <div class="min-w-32 flex-1 border-s border-secondary-soft ps-8 dark:border-line">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.target') }}</p>
                <p class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums text-secondary-medium dark:text-ink-muted">{{ $formatFigure((float) $planIndicator->target) }}{{ $unit }}</p>
                <p class="mt-1.5 type-meta text-secondary-medium dark:text-ink-muted">{{ $planIndicator->period->label() }}</p>
            </div>
        </div>
    </div>

    {{-- The connection: every node with a real destination is a real link — no dead-end labels dressed up as navigation. --}}
    <div>
        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.connection') }}</p>
        <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ __('reports.how_this_figure_traces_through_the_results_framework') }}</p>

        <div class="mt-5 flex flex-wrap items-start gap-x-2 gap-y-5">
            @foreach ($chain as $index => $node)
                @if ($index > 0)
                    <div class="mt-2.5 hidden h-px w-6 shrink-0 bg-secondary-soft @lg/detail:block dark:bg-line-strong" aria-hidden="true"></div>
                @endif
                <div class="min-w-36 flex-1" wire:key="chain-node-{{ $index }}">
                    <div class="flex items-center gap-2">
                        <span class="size-1.5 shrink-0 rounded-full bg-primary dark:bg-ink-muted" aria-hidden="true"></span>
                        <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ $node['label'] }}</span>
                    </div>
                    @if ($node['href'] !== null)
                        <a href="{{ $node['href'] }}" wire:navigate class="mt-1 flex items-center gap-1 ps-3.5 text-sm font-bold text-secondary-dark hover:text-primary hover:underline dark:text-ink dark:hover:text-brand-text">
                            <span class="truncate">{{ $node['value'] }}</span>
                            <flux:icon.arrow-up-right variant="micro" class="size-3 shrink-0 rtl:-scale-x-100" />
                        </a>
                    @else
                        <p class="mt-1 truncate ps-3.5 text-sm font-bold text-secondary-dark dark:text-ink">{{ $node['value'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @include('pages.reports._show-workflow')

    @if ($this->history->count() > 1)
        @include('pages.reports._show-history')
    @endif

    <x-ui.panel :title="__('reports.narrative_window')">
        <div class="flex flex-wrap gap-x-8 gap-y-4 p-4">
            <div class="min-w-28">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.window_start') }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums">{{ $this->report->period_start?->translatedFormat(__('formats.date')) ?? '—' }}</p>
            </div>
            <div class="min-w-28">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('reports.window_end') }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums">{{ $this->report->period_end?->translatedFormat(__('formats.date')) ?? '—' }}</p>
            </div>
        </div>

        @if ($this->report->narrative_report_notes !== null)
            <p class="border-t border-secondary-soft p-4 text-sm text-secondary-medium dark:border-line dark:text-ink-muted">{{ $this->report->narrative_report_notes }}</p>
        @endif
    </x-ui.panel>

    @php
        $narrativeSections = [
            'key_achievements' => ['label' => __('reports.key_achievements'), 'icon' => 'trophy'],
            'challenges' => ['label' => __('reports.challenges'), 'icon' => 'exclamation-triangle'],
            'lessons_learned' => ['label' => __('reports.lessons_learned'), 'icon' => 'light-bulb'],
            'next_period_plans' => ['label' => __('reports.next_period_plans'), 'icon' => 'arrow-trending-up'],
        ];
        $writtenSections = collect($narrativeSections)->filter(fn ($section, $field) => $this->report->{$field} !== null);
    @endphp

    {{-- The narrative reads as one continuous document rather than a wall of matching boxes: a fixed label column beside flowing text. --}}
    @if ($writtenSections->isNotEmpty())
        <div class="flex flex-col divide-y divide-secondary-soft dark:divide-line">
            @foreach ($writtenSections as $field => $section)
                <div class="flex flex-col gap-2 py-6 first:pt-0 last:pb-0 @lg/detail:flex-row @lg/detail:gap-8" wire:key="narrative-{{ $field }}">
                    <div class="flex shrink-0 items-center gap-2 @lg/detail:w-48">
                        <flux:icon :name="$section['icon']" variant="micro" class="size-4 shrink-0 text-secondary-medium dark:text-ink-muted" />
                        <p class="text-base font-bold text-secondary-dark dark:text-ink">{{ $section['label'] }}</p>
                    </div>
                    <p class="max-w-3xl flex-1 text-sm whitespace-pre-line text-secondary-medium dark:text-ink-muted">{{ $this->report->{$field} }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @unless ($report->isMirror())
        @include('pages.reports._show-files')
    @endunless

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['report' => [$this->reportId]]" :key="'report-activity-'.$this->reportId" />
    </section>

    <x-reports.form-modal
        :open="$showReportModal"
        :editing="$editingReportId !== null"
        :target-options="$showReportModal ? $this->targetOptions : []"
        :form-target="$showReportModal ? $this->formTarget : null"
        :window="$showReportModal ? $this->formWindow() : null"
        :ratio="$showReportModal ? $this->formRatio() : null"
        :ratio-preview="$showReportModal ? $this->ratioPreview() : null"
    />

    @include('pages.reports._show-file-modal')
</section>
</x-desk.record-sheet>
