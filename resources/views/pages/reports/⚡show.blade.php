<?php

use App\Enums\FileType;
use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Actions\Reports\ApproveReport;
use App\Actions\Reports\DeleteReportFile;
use App\Actions\Reports\MarkReportSeenByMeal;
use App\Actions\Reports\ReturnReportToDraft;
use App\Actions\Reports\SaveReportFile;
use App\Actions\Reports\SubmitReportForReview;
use App\Livewire\Concerns\ManagesReportForm;
use App\Livewire\Concerns\PreviewsFiles;
use App\Models\Report;
use App\Models\ReportFile;
use App\Rules\SecureUpload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('titles.report')] class extends Component {
    use ManagesReportForm;
    use PreviewsFiles;
    use WithFileUploads;

    #[Locked]
    public int $reportId;

    public bool $showFileModal = false;

    #[Locked]
    public ?int $editingFileId = null;

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public string $fileDisplayName = '';

    public string $fileDate = '';

    public bool $showDeleteFileModal = false;

    #[Locked]
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
                'planIndicator.indicator:id,name,result_id,measure,unit',
                'planIndicator.indicator.result:id,statement,type',
                'planIndicator.project:id,name',
                'planIndicator.source',
                // Every figure on the target, for its achievement by the shared rule.
                'planIndicator.reports:id,plan_indicator_id,status,planned_date,value',
                'user:id,hr_user_id',
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

    /** @return Collection<int, ReportFile> */
    #[Computed]
    public function files(): Collection
    {
        return ReportFile::query()
            ->select(['id', 'report_id', 'path', 'disk', 'original_name', 'size', 'date', 'type', 'uploaded_by'])
            ->with('uploader:id,hr_user_id')
            ->where('report_id', $this->reportId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
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
    public function submitForReview(SubmitReportForReview $submit): void
    {
        if (! $submit->handle(Report::query()->findOrFail($this->reportId))) {
            return;
        }

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_submitted_for_review'));
    }

    /**
     * Approve a reviewed report.
     *
     * Only valid from Reviewed.
     */
    public function approve(ApproveReport $approve): void
    {
        if (! $approve->handle(Report::query()->findOrFail($this->reportId))) {
            return;
        }

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_approved'));
    }

    /**
     * Send a reviewed or approved report back to draft.
     *
     * Editability is derived from status (Report::isEditable()), so returning
     * to draft is what reopens a report for editing — there is no separate
     * lock/unlock action any more. submit_date is cleared with it: a draft
     * has not been submitted, and submitting again stamps a fresh date.
     */
    public function returnToDraft(ReturnReportToDraft $returnToDraft): void
    {
        if (! $returnToDraft->handle(Report::query()->findOrFail($this->reportId))) {
            return;
        }

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
    public function markSeenByMeal(MarkReportSeenByMeal $markSeen): void
    {
        $markSeen->handle(Report::query()->findOrFail($this->reportId));

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.marked_as_seen_by_meal'));
    }

    public function openCreateFileModal(): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        Gate::authorize('update', $this->report);

        $this->resetFileForm();
        $this->fileDate = now()->toDateString();
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $fileId): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        Gate::authorize('update', $this->report);

        $file = $this->reportFile($fileId);

        $this->resetFileForm();
        $this->editingFileId = $file->id;
        $this->fileDisplayName = $file->displayName();
        $this->fileDate = $file->date->toDateString();
        $this->showFileModal = true;
    }

    /**
     * Upload a new supporting file, or update an existing one.
     *
     * Only available while the report itself is editable: a file backs up the
     * report's figure, so it follows the same draft-only lock. Editing changes
     * the display name and document date, and may swap in a replacement file —
     * the old one is then removed from disk.
     */
    public function saveFile(): void
    {
        if (! $this->report->isEditable()) {
            $this->closeFileModal();

            return;
        }

        Gate::authorize('update', $this->report);

        $isCreate = $this->editingFileId === null;
        $allowedTypes = implode(',', FileType::values());

        $validated = $this->validate([
            'newFile' => [$isCreate ? 'required' : 'nullable', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
            'fileDisplayName' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
            'fileDate' => ['required', 'date'],
        ], [
            'newFile.required' => __('reports.select_a_file_to_upload'),
            'fileDisplayName.required' => __('reports.enter_a_file_name'),
            'fileDate.required' => __('reports.set_the_file_date'),
        ]);

        app(SaveReportFile::class)->handle(
            $this->report,
            $isCreate ? null : $this->reportFile($this->editingFileId),
            $this->newFile,
            $validated,
        );

        $this->closeFileModal();
        unset($this->files);

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('reports.file_uploaded') : __('reports.file_updated'),
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
        $this->newFile = null;
        $this->fileDisplayName = '';
        $this->fileDate = '';
    }

    public function confirmDeleteFile(int $fileId): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        Gate::authorize('update', $this->report);

        $this->deletingFileId = $this->reportFile($fileId)->id;
        $this->showDeleteFileModal = true;
    }

    /**
     * Delete a file's row and, for a real upload, its file on disk.
     */
    public function deleteFile(): void
    {
        if ($this->deletingFileId === null || ! $this->report->isEditable()) {
            $this->closeDeleteFileModal();

            return;
        }

        Gate::authorize('update', $this->report);

        app(DeleteReportFile::class)->handle($this->report, $this->reportFile($this->deletingFileId));

        $this->closeDeleteFileModal();
        unset($this->files);

        Flux::toast(variant: 'success', text: __('reports.file_deleted'));
    }

    public function closeDeleteFileModal(): void
    {
        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
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

    /**
     * The file behind a file action, scoped to this report.
     *
     * The id comes from the client, so without this scope a user could act on
     * another report's file by tampering with it (IDOR).
     */
    private function reportFile(int $fileId): ReportFile
    {
        return ReportFile::query()->where('report_id', $this->reportId)->findOrFail($fileId);
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
                                'border-primary bg-primary dark:bg-ink-muted dark:border-ink-muted dark:text-surface-0 text-white' => $reached,
                                'border-secondary-light text-secondary-light dark:border-ink-faint dark:text-ink-faint' => ! $reached,
                            ])>
                                @if ($reached && ! $isCurrent)
                                    <flux:icon.check variant="micro" class="size-4" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
                            <span @class([
                                'type-meta whitespace-nowrap',
                                'font-semibold text-secondary-dark dark:text-ink' => $isCurrent,
                                'text-secondary-medium dark:text-ink-muted' => ! $isCurrent,
                            ])>{{ $step->label() }}</span>
                        </div>

                        @unless ($loop->last)
                            <span @class([
                                'mx-2 h-0.5 flex-1',
                                'bg-primary dark:bg-ink-muted' => $index < $currentIndex,
                                'bg-secondary-soft dark:bg-line-strong' => $index >= $currentIndex,
                            ])></span>
                        @endunless
                    </li>
                @endforeach
            </ol>

            <div class="flex flex-wrap items-center gap-3 border-t border-secondary-soft pt-3.5 dark:border-line">
                <div class="flex flex-wrap items-center gap-2 me-auto">
                    @if ($report->submit_date !== null)
                        <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                            {{ __('reports.submitted_date', ['date' => $report->submit_date->translatedFormat(__('formats.date'))]) }}
                        </span>
                    @endif

                    @if ($report->is_seen_by_meal)
                        <span class="rounded-md border border-status-good/30 bg-status-good-soft px-2 py-0.5 text-xs font-semibold text-status-good">{{ __('reports.seen_by_meal') }}</span>
                    @else
                        <span class="rounded-md border border-status-warn/30 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn">{{ __('reports.awaiting_meal_review') }}</span>
                    @endif

                    @unless ($report->isEditable())
                        <span class="rounded-md border border-primary bg-primary dark:bg-white/10 dark:text-ink dark:border-line px-2 py-0.5 text-xs font-semibold text-white">{{ __('reports.locked') }}</span>
                    @endunless
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Maker-checker: the author changes and submits; a second person approves (see ReportPolicy). --}}
                    @can('update', $report)
                        <x-ui.button size="sm" icon="pencil-square" wire:click="openEditModal({{ $report->id }})" data-test="edit-report-button">{{ __('reports.edit_report') }}</x-ui.button>
                    @endcan
                    @can('delete', $report)
                        <x-ui.button size="sm" icon="trash" wire:click="confirmDeleteReport({{ $report->id }})" data-test="delete-report-button">{{ __('reports.delete_report') }}</x-ui.button>
                    @endcan

                    @can('submit', $report)
                        <x-ui.button size="sm" tone="primary" icon="paper-airplane" wire:click="submitForReview" data-test="submit-for-review-button">{{ __('reports.submit_for_review') }}</x-ui.button>
                    @endcan

                    @can('approve', $report)
                        <x-ui.button size="sm" tone="primary" icon="check" wire:click="approve" data-test="approve-button">{{ __('reports.approve') }}</x-ui.button>
                    @endcan

                    @can('returnToDraft', $report)
                        <x-ui.button size="sm" icon="arrow-uturn-left" class="rtl:[&_svg]:-scale-x-100" wire:click="returnToDraft" data-test="return-to-draft-button">{{ __('reports.return_to_draft') }}</x-ui.button>
                    @endcan

                    @if (! $report->is_seen_by_meal && auth()->user()->can('markSeen', $report))
                        <x-ui.button size="sm" icon="eye" wire:click="markSeenByMeal" data-test="mark-seen-button">{{ __('reports.mark_seen_by_meal') }}</x-ui.button>
                    @endif
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

            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->history as $row)
                    @php $isCurrent = $row->id === $report->id; @endphp
                    <li wire:key="history-{{ $row->id }}">
                        @if ($isCurrent)
                            <div class="flex items-center gap-3 bg-status-critical-soft p-3.5 dark:bg-primary/10">
                                <span class="rounded-full bg-primary dark:bg-brand px-2 py-0.5 text-[11px] font-bold text-white">{{ __('reports.this_report') }}</span>
                                <span class="tabular-nums text-sm text-secondary-dark dark:text-ink">{{ $row->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                <span class="ms-auto text-sm font-semibold tabular-nums">{{ number_format((float) $row->value, 2) }}</span>
                                <x-ui.enum-badge :case="$row->status" />
                            </div>
                        @else
                            <a href="{{ route('reports.show', $row) }}" wire:navigate class="flex items-center gap-3 p-3.5 transition-colors hover:bg-board-head">
                                <span class="tabular-nums text-sm text-secondary-medium dark:text-ink-muted">{{ $row->planned_date?->translatedFormat(__('formats.date')) }}</span>
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

    <x-ui.panel :title="__('reports.supporting_files')" :count="$this->files->count()" :subtitle="__('reports.supporting_files_subtitle')">
        @can('update', $report)
            <x-slot:toolbar>
                <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openCreateFileModal" data-test="create-file-button">
                    {{ __('reports.upload_file') }}
                </x-ui.button>
            </x-slot:toolbar>
        @endcan

        @if ($this->files->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('reports.supporting_files_for_this_report_will_appear_here')">
                @can('update', $report)
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openCreateFileModal">{{ __('reports.upload_file') }}</x-ui.button>
                    </x-slot:action>
                @endcan
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->files as $file)
                    @php
                        $fileName = $file->displayName();
                        $isStored = $file->isStored();
                        $fileIcon = match ($file->type) {
                            FileType::Xlsx => 'table-cells',
                            FileType::Jpg, FileType::Png => 'photo',
                            default => 'document-text',
                        };
                    @endphp
                    <li class="flex flex-wrap items-center gap-3 p-3.5" wire:key="report-file-{{ $file->id }}">
                        @if ($isStored && in_array($file->type, [FileType::Jpg, FileType::Png], true))
                            <button type="button" wire:click="openPreview({{ $file->id }})" class="size-10 shrink-0 overflow-hidden rounded-xl border border-secondary-soft dark:border-line" aria-label="{{ __('reports.preview_name', ['name' => $fileName]) }}">
                                <img src="{{ route('reports.files.preview', [$report, $file]) }}" alt="" loading="lazy" class="size-full object-cover" data-no-dim />
                            </button>
                        @else
                            <span class="inline-flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border border-secondary-soft bg-board-head dark:border-line dark:bg-surface-2">
                                <flux:icon :name="$fileIcon" variant="micro" class="size-3.5 text-secondary-medium dark:text-ink-muted" />
                                <span class="text-[10px] font-bold text-secondary-dark dark:text-ink">{{ Str::upper($file->type->value) }}</span>
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            @if ($isStored)
                                <button type="button" wire:click="openPreview({{ $file->id }})" class="block max-w-full truncate text-start text-sm font-medium hover:text-primary dark:hover:text-brand-text hover:underline" title="{{ $fileName }}" data-test="preview-file-name">{{ $fileName }}</button>
                            @else
                                <p class="truncate text-sm font-medium" title="{{ $fileName }}">{{ $fileName }}</p>
                            @endif
                            <p class="flex flex-wrap items-center gap-x-1.5 text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                <time datetime="{{ $file->date?->toDateString() }}">{{ $file->date?->translatedFormat(__('formats.date')) }}</time>
                                @if ($file->size !== null)
                                    <span aria-hidden="true">&middot;</span>
                                    <span>{{ \Illuminate\Support\Number::fileSize($file->size, precision: 1) }}</span>
                                @endif
                                @if ($file->uploader !== null)
                                    <span aria-hidden="true">&middot;</span>
                                    <span class="truncate">{{ $file->uploader->name }}</span>
                                @endif
                                @unless ($isStored)
                                    <span class="rounded-md border border-secondary-light px-1.5 text-[11px] font-semibold">{{ __('reports.reference_only') }}</span>
                                @endunless
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            @if ($isStored)
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" wire:click="openPreview({{ $file->id }})" :aria-label="__('reports.preview_name', ['name' => $fileName])" data-test="preview-file-button" />
                                <a
                                    href="{{ route('reports.files.download', [$report, $file]) }}"
                                    class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink"
                                    aria-label="{{ __('common.download_name', ['name' => $fileName]) }}"
                                    data-test="download-file-link"
                                >
                                    <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                                </a>
                            @endif

                            @can('update', $report)
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditFileModal({{ $file->id }})" :aria-label="__('common.edit_name', ['name' => $fileName])" data-test="edit-file-button" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8! text-primary! dark:text-brand-text! hover:bg-status-critical-soft!" wire:click="confirmDeleteFile({{ $file->id }})" :aria-label="__('common.delete_name', ['name' => $fileName])" data-test="delete-file-button" />
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

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
    />

    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('reports.upload_file') : __('reports.edit_file')"
        :description="__('reports.allowed_file_types_and_size', ['types' => Str::upper(implode(', ', FileType::values())), 'size' => '5 MB'])"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                <x-ui.form-file
                    wire:model="newFile"
                    :label="$editingFileId === null ? __('reports.choose_a_file') : __('reports.replace_file_optional')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                    :required="$editingFileId === null"
                />

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-input wire:model="fileDisplayName" :label="__('reports.file_display_name')" :placeholder="$editingFileId === null ? __('reports.defaults_to_the_uploaded_file_name') : null" :required="$editingFileId !== null" />
                    <x-ui.form-input wire:model="fileDate" :label="__('reports.document_date')" type="date" required />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-file-button">
                {{ $editingFileId === null ? __('reports.upload_file') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    @php
        $previewFile = $showPreviewModal ? $this->files->firstWhere('id', $previewingFileId) : null;
        $previewMeta = $previewFile === null ? null : collect([
            Str::upper($previewFile->type->value),
            $previewFile->size !== null ? \Illuminate\Support\Number::fileSize($previewFile->size, precision: 1) : null,
            $previewFile->date?->translatedFormat(__('formats.date')),
            $this->files->count() > 1 ? __('reports.preview_position', [
                'position' => $this->files->search(fn ($candidate) => $candidate->id === $previewFile->id) + 1,
                'total' => $this->files->count(),
            ]) : null,
        ])->filter()->implode(' · ');
    @endphp

    <x-files.preview-modal
        :file="$previewFile"
        :name="$previewFile?->displayName() ?? ''"
        :description="$previewMeta"
        :preview-url="$previewFile ? route('reports.files.preview', [$report, $previewFile]) : null"
        :download-url="$previewFile ? route('reports.files.download', [$report, $previewFile]) : null"
        :count="$this->files->count()"
    />
    <x-ui.confirm-modal
        wire:model="showDeleteFileModal"
        close="closeDeleteFileModal"
        :title="__('reports.delete_this_file')"
        :message="$showDeleteFileModal ? __('reports.deleting_the_file_also_removes_it_from_storage', ['name' => $this->files->firstWhere('id', $deletingFileId)?->displayName() ?? '']) : null"
        confirm="deleteFile"
        :confirm-label="__('reports.delete_this_file')"
        confirm-test="confirm-delete-file-button"
    />
</section>
