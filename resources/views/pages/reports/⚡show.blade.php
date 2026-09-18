<?php

use App\Enums\FileType;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\ReportFile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.report')] class extends Component {
    public int $reportId;

    public bool $showFileModal = false;

    public ?int $editingFileId = null;

    public string $fileType = '';

    public string $filePath = '';

    public string $fileDate = '';

    public bool $showDeleteFileModal = false;

    public ?int $deletingFileId = null;

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
                'value', 'narrative_report_notes', 'executive_summary', 'key_achievements', 'challenges',
                'lessons_learned', 'next_period_plans', 'submit_date', 'is_seen_by_meal',
            ])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,result_id,measure',
                'planIndicator.indicator.result:id,statement,type',
                'planIndicator.project:id,name',
                'planIndicator.source',
                'user:id,name,email',
            ])
            ->findOrFail($this->reportId);
    }

    /** @return Collection<int, ReportFile> */
    #[Computed]
    public function files(): Collection
    {
        return ReportFile::query()
            ->select(['id', 'report_id', 'path', 'date', 'type'])
            ->where('report_id', $this->reportId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The share of the target this figure reaches, capped for display.
     *
     * Returns null when the target is zero, so the tile renders an em dash
     * rather than dividing by nothing.
     */
    #[Computed]
    public function achievement(): ?int
    {
        $target = (float) $this->report->planIndicator->target;

        if ($target <= 0.0) {
            return null;
        }

        return (int) floor(((float) $this->report->value / $target) * 100);
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
     * Every report against this same target, newest first, this one included.
     *
     * This is what lets the detail page show the figure in the context of its
     * own reporting history rather than as an isolated number.
     *
     * @return Collection<int, Report>
     */
    #[Computed]
    public function history(): Collection
    {
        return Report::query()
            ->select(['id', 'status', 'planned_date', 'value', 'is_seen_by_meal'])
            ->where('plan_indicator_id', $this->report->plan_indicator_id)
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Submit a draft for review.
     *
     * Only valid from Draft. Writes status and submit_date together so the two
     * can never disagree about whether — and when — this was submitted.
     */
    public function submitForReview(): void
    {
        $report = Report::query()->findOrFail($this->reportId);

        if ($report->status !== ReportStatus::Draft) {
            return;
        }

        $report->fill([
            'status' => ReportStatus::Reviewed,
            'submit_date' => now()->toDateString(),
        ])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_submitted_for_review'));
    }

    /**
     * Approve a reviewed report.
     *
     * Only valid from Reviewed.
     */
    public function approve(): void
    {
        $report = Report::query()->findOrFail($this->reportId);

        if ($report->status !== ReportStatus::Reviewed) {
            return;
        }

        $report->fill(['status' => ReportStatus::Approved])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_approved'));
    }

    /**
     * Send a reviewed or approved report back to draft.
     *
     * Editability is derived from status (Report::isEditable()), so returning
     * to draft is what reopens a report for editing — there is no separate
     * lock/unlock action any more.
     */
    public function returnToDraft(): void
    {
        $report = Report::query()->findOrFail($this->reportId);

        if (! in_array($report->status, [ReportStatus::Reviewed, ReportStatus::Approved], true)) {
            return;
        }

        $report->fill(['status' => ReportStatus::Draft])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_returned_to_draft'));
    }

    /**
     * Acknowledge that MEAL has reviewed this figure.
     *
     * One way on purpose: is_seen_by_meal has no paired date column, so there
     * is nothing to record about un-seeing it, and "MEAL never looked at this"
     * is not a state a reviewer can restore.
     */
    public function markSeenByMeal(): void
    {
        Report::query()->findOrFail($this->reportId)->fill(['is_seen_by_meal' => true])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.marked_as_seen_by_meal'));
    }

    public function openCreateFileModal(): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        $this->resetFileForm();
        $this->fileDate = now()->toDateString();
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $fileId): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        $file = ReportFile::query()->where('report_id', $this->reportId)->findOrFail($fileId);

        $this->resetValidation();
        $this->editingFileId = $file->id;
        $this->fileType = $file->type->value;
        $this->filePath = $file->path;
        $this->fileDate = $file->date->toDateString();
        $this->showFileModal = true;
    }

    /**
     * Create or update a supporting file record.
     *
     * Only available while the report itself is editable: a file record backs
     * up the report's figure, so it follows the same draft-only lock.
     */
    public function saveFile(): void
    {
        if (! $this->report->isEditable()) {
            $this->showFileModal = false;
            $this->resetFileForm();

            return;
        }

        $validated = $this->validate([
            'fileType' => ['required', Rule::in(FileType::values())],
            'filePath' => ['required', 'string', 'max:255'],
            'fileDate' => ['required', 'date'],
        ], [
            'fileType.required' => __('reports.select_the_file_type'),
            'filePath.required' => __('reports.enter_a_reference_or_file_name'),
            'fileDate.required' => __('reports.set_the_file_date'),
        ]);

        $isCreate = $this->editingFileId === null;

        $file = $isCreate
            ? new ReportFile(['report_id' => $this->reportId])
            : ReportFile::query()->where('report_id', $this->reportId)->findOrFail($this->editingFileId);

        $file->fill([
            'type' => $validated['fileType'],
            'path' => $validated['filePath'],
            'date' => $validated['fileDate'],
        ])->save();

        $this->showFileModal = false;
        $this->resetFileForm();
        unset($this->files);

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('reports.file_recorded') : __('reports.file_updated'),
        );
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->resetFileForm();
    }

    protected function resetFileForm(): void
    {
        $this->resetValidation();
        $this->editingFileId = null;
        $this->fileType = '';
        $this->filePath = '';
        $this->fileDate = '';
    }

    public function confirmDeleteFile(int $fileId): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        $this->deletingFileId = $fileId;
        $this->showDeleteFileModal = true;
    }

    public function deleteFile(): void
    {
        if ($this->deletingFileId === null) {
            return;
        }

        ReportFile::query()
            ->where('report_id', $this->reportId)
            ->where('id', $this->deletingFileId)
            ->delete();

        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
        unset($this->files);

        Flux::toast(variant: 'success', text: __('reports.file_deleted'));
    }

    public function closeDeleteFileModal(): void
    {
        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
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
        $planIndicator->source_type === 'annual_plan' && Route::has('annual-plans.show') => route('annual-plans.show', $planIndicator->source_id),
        $planIndicator->source_type !== 'annual_plan' && Route::has('proposals.show') => route('proposals.show', $planIndicator->source_id),
        default => null,
    };

    // Every node the reader can act on carries a real href: no dead-end labels
    // dressed up as links. Indicators and results have no page of their own
    // yet, so "Output" is the closest working connection — the filtered
    // indicators list for that same result.
    $chain = [
        [
            'label' => $planIndicator->indicator->result->type->label(),
            'value' => Str::limit($planIndicator->indicator->result->statement, 64),
            'href' => Route::has('indicators.index') ? route('indicators.index', ['result' => $planIndicator->indicator->result_id]) : null,
        ],
        ['label' => __('common.indicator_name'), 'value' => $planIndicator->indicator->name, 'href' => null],
        [
            'label' => __('common.project'),
            'value' => $planIndicator->project?->name ?? __('common.organisation_wide'),
            'href' => $planIndicator->project !== null ? route('projects.show', $planIndicator->project) : null,
        ],
        ['label' => __('common.period'), 'value' => $planIndicator->period->label(), 'href' => null],
        ['label' => __('reports.committed_via'), 'value' => $sourceValue, 'href' => $sourceHref],
    ];
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.reports'), 'url' => route('reports.index')],
            ['label' => $planIndicator->indicator->name],
        ]"
        :title="$planIndicator->indicator->name"
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
                @elseif ($sourceHref !== null)
                    {{ __('reports.committed_in_a_proposal_2') }}
                    <a href="{{ $sourceHref }}" wire:navigate class="hover:underline">{{ $planIndicator->source->name }}</a>
                @else
                    {{ __('reports.committed_in_a_proposal') }}
                @endif
            </span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$report->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    {{-- The hero: the figure the page exists to show, set directly on the page rather than boxed into cards. --}}
    <div class="flex flex-col gap-8 border-y border-secondary-soft py-8 dark:border-secondary-medium @lg/detail:flex-row @lg/detail:items-center">
        <x-ui.progress-ring :value="$this->achievement" size="lg" :tone="$this->achievementTone" :label="__('reports.achievement')" class="shrink-0" />

        <div class="flex flex-1 flex-wrap items-stretch gap-y-6">
            <div class="min-w-32 flex-1">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('reports.reported') }}</p>
                <p class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums">{{ number_format((float) $report->value, 2) }}</p>
            </div>
            <div class="min-w-32 flex-1 border-s border-secondary-soft ps-8 dark:border-secondary-medium">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('reports.target') }}</p>
                <p class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ number_format((float) $planIndicator->target, 2) }}</p>
            </div>
        </div>
    </div>

    {{-- The connection: every node with a real destination is a real link — no dead-end labels dressed up as navigation. --}}
    <div>
        <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('reports.connection') }}</p>
        <p class="mt-1 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('reports.how_this_figure_traces_through_the_results_framework') }}</p>

        <div class="mt-5 flex flex-wrap items-start gap-x-2 gap-y-5">
            @foreach ($chain as $index => $node)
                @if ($index > 0)
                    <div class="mt-2.5 hidden h-px w-6 shrink-0 bg-secondary-soft @lg/detail:block dark:bg-secondary-medium" aria-hidden="true"></div>
                @endif
                <div class="min-w-36 flex-1" wire:key="chain-node-{{ $index }}">
                    <div class="flex items-center gap-2">
                        <span class="size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                        <span class="type-meta text-secondary-medium dark:text-secondary-soft">{{ $node['label'] }}</span>
                    </div>
                    @if ($node['href'] !== null)
                        <a href="{{ $node['href'] }}" wire:navigate class="mt-1 flex items-center gap-1 ps-3.5 text-sm font-bold text-secondary-dark hover:text-primary hover:underline dark:text-white dark:hover:text-primary">
                            <span class="truncate">{{ $node['value'] }}</span>
                            <flux:icon.arrow-up-right variant="micro" class="size-3 shrink-0 rtl:-scale-x-100" />
                        </a>
                    @else
                        <p class="mt-1 truncate ps-3.5 text-sm font-bold text-secondary-dark dark:text-white">{{ $node['value'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <x-ui.panel :title="__('reports.workflow')" :subtitle="__('reports.each_action_moves_the_report_through_draft_reviewed_and_appr')">
        <div class="flex flex-col gap-4 p-3.5">
            @php
                $steps = [ReportStatus::Draft, ReportStatus::Reviewed, ReportStatus::Approved];
                $currentIndex = array_search($report->status, $steps, true);
            @endphp

            <ol class="flex items-center" role="list" aria-label="{{ __('reports.workflow') }}">
                @foreach ($steps as $index => $step)
                    @php
                        $reached = $index <= $currentIndex;
                        $isCurrent = $index === $currentIndex;
                    @endphp
                    <li class="flex flex-1 items-center last:flex-none" wire:key="workflow-step-{{ $step->value }}">
                        <div class="flex flex-col items-center gap-1.5">
                            <span @class([
                                'flex size-8 items-center justify-center rounded-full border-2 text-xs font-bold',
                                'border-primary bg-primary text-white' => $reached,
                                'border-secondary-light text-secondary-light dark:border-secondary-medium dark:text-secondary-medium' => ! $reached,
                            ])>
                                @if ($reached && ! $isCurrent)
                                    <flux:icon.check variant="micro" class="size-4" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
                            <span @class([
                                'type-meta whitespace-nowrap',
                                'font-semibold text-secondary-dark dark:text-white' => $isCurrent,
                                'text-secondary-medium dark:text-secondary-soft' => ! $isCurrent,
                            ])>{{ $step->label() }}</span>
                        </div>

                        @unless ($loop->last)
                            <span @class([
                                'mx-2 h-0.5 flex-1',
                                'bg-primary' => $index < $currentIndex,
                                'bg-secondary-soft dark:bg-secondary-medium' => $index >= $currentIndex,
                            ])></span>
                        @endunless
                    </li>
                @endforeach
            </ol>

            <div class="flex flex-wrap items-center gap-3 border-t border-secondary-soft pt-3.5 dark:border-secondary-medium">
                <div class="flex flex-wrap items-center gap-2 me-auto">
                    @if ($report->submit_date !== null)
                        <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                            {{ __('reports.submitted_date', ['date' => $report->submit_date->translatedFormat(__('formats.date'))]) }}
                        </span>
                    @endif

                    @if ($report->is_seen_by_meal)
                        <span class="rounded-md border border-status-good/30 bg-status-good-soft px-2 py-0.5 text-xs font-semibold text-status-good">{{ __('reports.seen_by_meal') }}</span>
                    @else
                        <span class="rounded-md border border-status-warn/30 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn">{{ __('reports.awaiting_meal_review') }}</span>
                    @endif

                    @unless ($report->isEditable())
                        <span class="rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('reports.locked') }}</span>
                    @endunless
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($report->status === ReportStatus::Draft)
                        <x-ui.button size="sm" tone="primary" icon="paper-airplane" wire:click="submitForReview" data-test="submit-for-review-button">{{ __('reports.submit_for_review') }}</x-ui.button>
                    @endif

                    @if ($report->status === ReportStatus::Reviewed)
                        <x-ui.button size="sm" tone="primary" icon="check" wire:click="approve" data-test="approve-button">{{ __('reports.approve') }}</x-ui.button>
                    @endif

                    @if (in_array($report->status, [ReportStatus::Reviewed, ReportStatus::Approved], true))
                        <x-ui.button size="sm" icon="arrow-uturn-left" class="rtl:[&_svg]:-scale-x-100" wire:click="returnToDraft" data-test="return-to-draft-button">{{ __('reports.return_to_draft') }}</x-ui.button>
                    @endif

                    @unless ($report->is_seen_by_meal)
                        <x-ui.button size="sm" icon="eye" wire:click="markSeenByMeal" data-test="mark-seen-button">{{ __('reports.mark_seen_by_meal') }}</x-ui.button>
                    @endunless
                </div>
            </div>
        </div>
    </x-ui.panel>

    @if ($this->history->count() > 1)
        <x-ui.panel :title="__('reports.report_history')" :count="$this->history->count()" :subtitle="__('reports.every_report_against_this_same_target_newest_first')">
            @php $trendRows = $this->history->sortBy('planned_date')->values(); @endphp
            <div class="px-4 pt-4 pb-2">
                <x-ui.chart
                    type="area"
                    :label="__('reports.report_history')"
                    :series="[['name' => __('reports.reported_value'), 'data' => $trendRows->map(fn ($row) => (float) $row->value)->all()]]"
                    :categories="$trendRows->map(fn ($row) => $row->planned_date?->translatedFormat(__('formats.date_short')) ?? '—')->all()"
                    :tones="['critical']"
                    :height="160"
                />
            </div>

            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->history as $row)
                    @php $isCurrent = $row->id === $report->id; @endphp
                    <li wire:key="history-{{ $row->id }}">
                        @if ($isCurrent)
                            <div class="flex items-center gap-3 bg-status-critical-soft p-3.5 dark:bg-primary/10">
                                <span class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-bold text-white">{{ __('reports.this_report') }}</span>
                                <span class="tabular-nums text-sm text-secondary-dark dark:text-white">{{ $row->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                <span class="ms-auto text-sm font-semibold tabular-nums">{{ number_format((float) $row->value, 2) }}</span>
                                <x-ui.enum-badge :case="$row->status" />
                            </div>
                        @else
                            <a href="{{ route('reports.show', $row) }}" wire:navigate class="flex items-center gap-3 p-3.5 transition-colors hover:bg-board-head">
                                <span class="tabular-nums text-sm text-secondary-medium dark:text-secondary-soft">{{ $row->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                <span class="ms-auto text-sm font-semibold tabular-nums">{{ number_format((float) $row->value, 2) }}</span>
                                <x-ui.enum-badge :case="$row->status" />
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.panel>
    @endif

    <x-ui.panel :title="__('reports.narrative_window')">
        <div class="flex flex-wrap gap-x-8 gap-y-4 p-4">
            <div class="min-w-28">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('reports.window_start') }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums">{{ $this->report->period_start?->translatedFormat(__('formats.date')) ?? '—' }}</p>
            </div>
            <div class="min-w-28">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('reports.window_end') }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums">{{ $this->report->period_end?->translatedFormat(__('formats.date')) ?? '—' }}</p>
            </div>
        </div>

        @if ($this->report->narrative_report_notes !== null)
            <p class="border-t border-secondary-soft p-4 text-sm text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">{{ $this->report->narrative_report_notes }}</p>
        @endif
    </x-ui.panel>

    @php
        $narrativeSections = [
            'executive_summary' => ['label' => __('reports.executive_summary'), 'icon' => 'document-text'],
            'key_achievements' => ['label' => __('reports.key_achievements'), 'icon' => 'trophy'],
            'challenges' => ['label' => __('reports.challenges'), 'icon' => 'exclamation-triangle'],
            'lessons_learned' => ['label' => __('reports.lessons_learned'), 'icon' => 'light-bulb'],
            'next_period_plans' => ['label' => __('reports.next_period_plans'), 'icon' => 'arrow-trending-up'],
        ];
        $writtenSections = collect($narrativeSections)->filter(fn ($section, $field) => $this->report->{$field} !== null);
    @endphp

    {{-- The narrative reads as one continuous document rather than a wall of matching boxes: a fixed label column beside flowing text. --}}
    @if ($writtenSections->isNotEmpty())
        <div class="flex flex-col divide-y divide-secondary-soft dark:divide-secondary-medium">
            @foreach ($writtenSections as $field => $section)
                <div class="flex flex-col gap-2 py-6 first:pt-0 last:pb-0 @lg/detail:flex-row @lg/detail:gap-8" wire:key="narrative-{{ $field }}">
                    <div class="flex shrink-0 items-center gap-2 @lg/detail:w-48">
                        <flux:icon :name="$section['icon']" variant="micro" class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" />
                        <p class="text-base font-bold text-secondary-dark dark:text-white">{{ $section['label'] }}</p>
                    </div>
                    <p class="max-w-3xl flex-1 text-sm whitespace-pre-line text-secondary-medium dark:text-secondary-soft">{{ $this->report->{$field} }}</p>
                </div>
            @endforeach
        </div>
    @endif

    <x-ui.panel :title="__('reports.supporting_files')" :count="$this->files->count()" :subtitle="__('common.file_records_only_the_files_themselves_are_not_stored_in_thi')">
        @if ($report->isEditable())
            <x-slot:toolbar>
                <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateFileModal" data-test="create-file-button">
                    {{ __('reports.add_file') }}
                </x-ui.button>
            </x-slot:toolbar>
        @endif

        @if ($this->files->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('reports.supporting_file_records_for_this_report_will_appear_here')" />
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->files as $file)
                    {{-- Deliberately not a link: storage/app holds no files. --}}
                    <li class="flex items-center gap-3 p-3.5" wire:key="report-file-{{ $file->id }}">
                        <span class="inline-flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border border-secondary-soft bg-board-head dark:border-secondary-medium dark:bg-secondary-medium/30">
                            <flux:icon name="document-text" variant="micro" class="size-3.5 text-secondary-medium dark:text-secondary-soft" />
                            <span class="text-[10px] font-bold text-secondary-dark dark:text-white">{{ Str::upper($file->type->value) }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ basename($file->path) }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $file->date?->translatedFormat(__('formats.date')) }}</p>
                        </div>

                        @if ($report->isEditable())
                            <div class="flex shrink-0 items-center gap-1">
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditFileModal({{ $file->id }})" :aria-label="__('common.edit_name', ['name' => basename($file->path)])" data-test="edit-file-button" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8! text-primary! hover:bg-status-critical-soft!" wire:click="confirmDeleteFile({{ $file->id }})" :aria-label="__('common.delete_name', ['name' => basename($file->path)])" data-test="delete-file-button" />
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <flux:modal wire:model="showFileModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showFileModal)
            <form wire:submit="saveFile" class="space-y-5">
                <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                    <flux:heading size="lg">{{ $editingFileId === null ? __('reports.add_file') : __('reports.edit_file') }}</flux:heading>
                    <flux:subheading>{{ __('common.file_records_only_the_files_themselves_are_not_stored_in_thi') }}</flux:subheading>
                </div>

                <x-ui.select wire:model="fileType" :label="__('reports.file_type')" required>
                    <flux:select.option value="">{{ __('reports.select_a_file_type') }}</flux:select.option>
                    @foreach (FileType::cases() as $typeCase)
                        <flux:select.option :value="$typeCase->value" :wire:key="'file-type-'.$typeCase->value">{{ Str::upper($typeCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.input wire:model="filePath" :label="__('reports.file_reference')" :placeholder="__('reports.file_reference_placeholder')" required />

                <x-ui.input wire:model="fileDate" :label="__('common.date')" type="date" required />

                <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                    <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeFileModal">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-file-button">
                        {{ $editingFileId === null ? __('reports.add_file') : __('common.save_changes') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal wire:model="showDeleteFileModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showDeleteFileModal)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('reports.delete_file_record') }}</flux:heading>
                    <flux:subheading>{{ __('reports.this_removes_the_file_record_only_it_cannot_be_undone') }}</flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button wire:click="closeDeleteFileModal">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button tone="primary" wire:click="deleteFile" data-test="confirm-delete-file-button">{{ __('common.delete_name', ['name' => __('reports.the_file_record')]) }}</x-ui.button>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
