<?php

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\ReportFile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Report')] class extends Component {
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
                'value', 'narrative_report_notes', 'executive_summary', 'key_achievements', 'challenges',
                'lessons_learned', 'next_period_plans', 'submit_date', 'is_seen_by_meal',
            ])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,result_id,measure',
                'planIndicator.indicator.result:id,statement,type',
                'planIndicator.project:id,name',
                'planIndicator.source',
                'user:id,name,email,role',
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

        Flux::toast(variant: 'success', text: __('Report submitted for review.'));
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

        Flux::toast(variant: 'success', text: __('Report approved.'));
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

        Flux::toast(variant: 'success', text: __('Report returned to draft.'));
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

        Flux::toast(variant: 'success', text: __('Marked as seen by MEAL.'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Reports'), 'url' => route('reports.index')],
            ['label' => $this->report->planIndicator->indicator->name],
        ]"
        :title="$this->report->planIndicator->indicator->name"
        :subtitle="$this->report->planIndicator->indicator->result->statement"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ __('Period to :date', ['date' => $this->report->planned_date?->format('M j, Y')]) }}</span>
            <span>{{ $this->report->planIndicator->project?->name ?? __('Organisation-wide') }}</span>
            <span>
                @if ($this->report->planIndicator->source === null)
                    {{ __('Source no longer exists') }}
                @elseif ($this->report->planIndicator->source_type === 'yearly_plan')
                    {{ __('Committed in an annual plan') }}
                @elseif (Route::has('proposals.show'))
                    {{ __('Committed in a proposal:') }}
                    <a href="{{ route('proposals.show', $this->report->planIndicator->source_id) }}" wire:navigate class="hover:underline">{{ $this->report->planIndicator->source->name }}</a>
                @else
                    {{ __('Committed in a proposal') }}
                @endif
            </span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$this->report->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('Reported')" :value="number_format((float) $this->report->value, 2)" />
        <x-ui.stat-tile :label="__('Target')" :value="number_format((float) $this->report->planIndicator->target, 2)" />
        <x-ui.stat-tile :label="__('Achievement')" :value="$this->achievement === null ? '—' : $this->achievement.'%'" />
        <x-ui.stat-tile :label="__('Period')" :value="Str::title($this->report->planIndicator->period->value)" />
    </div>

    <x-ui.panel :title="__('Workflow')" :subtitle="__('Each action moves the report through draft, reviewed and approved. Editing is only possible while a report is a draft.')">
        <div class="flex flex-wrap items-center gap-3 p-3.5">
            <div class="flex flex-wrap items-center gap-2 me-auto">
                <x-ui.enum-badge :case="$this->report->status" />

                @if ($this->report->submit_date !== null)
                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                        {{ __('Submitted :date', ['date' => $this->report->submit_date->format('M j, Y')]) }}
                    </span>
                @endif

                @if ($this->report->is_seen_by_meal)
                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Seen by MEAL') }}</span>
                @endif

                @unless ($this->report->isEditable())
                    <span class="rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('Locked') }}</span>
                @endunless
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($this->report->status === ReportStatus::Draft)
                    <x-ui.button size="sm" tone="primary" icon="paper-airplane" wire:click="submitForReview" data-test="submit-for-review-button">{{ __('Submit for review') }}</x-ui.button>
                @endif

                @if ($this->report->status === ReportStatus::Reviewed)
                    <x-ui.button size="sm" tone="primary" icon="check" wire:click="approve" data-test="approve-button">{{ __('Approve') }}</x-ui.button>
                @endif

                @if (in_array($this->report->status, [ReportStatus::Reviewed, ReportStatus::Approved], true))
                    <x-ui.button size="sm" icon="arrow-uturn-left" wire:click="returnToDraft" data-test="return-to-draft-button">{{ __('Return to draft') }}</x-ui.button>
                @endif

                @unless ($this->report->is_seen_by_meal)
                    <x-ui.button size="sm" icon="eye" wire:click="markSeenByMeal" data-test="mark-seen-button">{{ __('Mark seen by MEAL') }}</x-ui.button>
                @endunless
            </div>
        </div>
    </x-ui.panel>

    <x-ui.panel :title="__('Narrative window')">
        <div class="grid gap-3 p-3.5 @lg/detail:grid-cols-2">
            <x-ui.stat-tile :label="__('Window start')" :value="$this->report->period_start?->format('M j, Y') ?? '—'" />
            <x-ui.stat-tile :label="__('Window end')" :value="$this->report->period_end?->format('M j, Y') ?? '—'" />
        </div>

        @if ($this->report->narrative_report_notes !== null)
            <p class="border-t border-secondary-soft p-3.5 text-sm text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">{{ $this->report->narrative_report_notes }}</p>
        @endif
    </x-ui.panel>

    @php
        $narrativeSections = [
            'executive_summary' => __('Executive summary'),
            'key_achievements' => __('Key achievements'),
            'challenges' => __('Challenges'),
            'lessons_learned' => __('Lessons learned'),
            'next_period_plans' => __('Next period plans'),
        ];
    @endphp

    @foreach ($narrativeSections as $field => $label)
        @if ($this->report->{$field} !== null)
            <x-ui.panel :title="$label">
                <p class="p-3.5 text-sm whitespace-pre-line text-secondary-medium dark:text-secondary-soft">{{ $this->report->{$field} }}</p>
            </x-ui.panel>
        @endif
    @endforeach

    <x-ui.panel :title="__('Supporting files')" :count="$this->files->count()" :subtitle="__('File records only. The files themselves are not stored in this system yet.')">
        @if ($this->files->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('No files recorded')" :body="__('Supporting file records for this report will appear here.')" />
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->files as $file)
                    {{-- Deliberately not a link: storage/app holds no files. --}}
                    <li class="flex items-center gap-3 p-3.5" wire:key="report-file-{{ $file->id }}">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                            {{ Str::upper($file->type->value) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ basename($file->path) }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $file->date?->format('M j, Y') }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>
</section>
