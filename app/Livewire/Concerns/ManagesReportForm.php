<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\IndicatorMeasure;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The report form and draft deletion, shared by the report directory and the
 * report detail page so a report is recorded, edited and removed by exactly
 * one set of rules wherever the manager happens to be.
 *
 * The using component renders resources/views/components/reports/form-modal
 * and delete-modal, and decides where to land after a save or delete through
 * reportSaved() and reportDeleted().
 */
trait ManagesReportForm
{
    public bool $showReportModal = false;

    #[Locked]
    public ?int $editingReportId = null;

    public string $reportTargetId = '';

    public string $plannedDate = '';

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $value = '';

    public string $narrativeNotes = '';

    public string $executiveSummary = '';

    public string $keyAchievements = '';

    public string $challenges = '';

    public string $lessonsLearned = '';

    public string $nextPeriodPlans = '';

    public bool $showDeleteReportModal = false;

    #[Locked]
    public ?int $deletingReportId = null;

    /**
     * Called after a report is recorded or edited.
     */
    abstract protected function reportSaved(Report $report, bool $isCreate): void;

    /**
     * Called after a draft report is deleted.
     */
    abstract protected function reportDeleted(): void;

    /**
     * Every target a figure can be reported against, labelled so two targets
     * on one indicator can be told apart: the indicator, where it was
     * committed, who delivers it, and the committed value.
     *
     * A target whose plan or proposal is gone is left out: there is nothing
     * left to report to.
     *
     * @return list<array{id: int, label: string, measure: IndicatorMeasure}>
     */
    #[Computed]
    public function targetOptions(): array
    {
        return PlanIndicator::query()
            ->select(['id', 'indicator_id', 'source_type', 'source_id', 'target', 'period', 'project_id'])
            ->with([
                'indicator:id,name,measure',
                'project:id,name',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([AnnualPlan::class => [], Proposal::class => []]),
            ])
            ->get()
            ->filter(fn (PlanIndicator $target): bool => $target->source !== null)
            ->sortBy(fn (PlanIndicator $target): string => mb_strtolower($target->indicator->name).'#'.str_pad((string) $target->id, 10, '0', STR_PAD_LEFT))
            ->map(fn (PlanIndicator $target): array => [
                'id' => $target->id,
                'label' => $this->targetLabel($target),
                'measure' => $target->indicator->measure,
            ])
            ->values()
            ->all();
    }

    /**
     * One target, as the report form names it.
     */
    protected function targetLabel(PlanIndicator $target): string
    {
        $source = $target->source;
        $committedIn = match (true) {
            $source instanceof AnnualPlan => __('common.fy_year', ['year' => $source->year]),
            $source instanceof Proposal => $source->name,
            default => __('targets.source_no_longer_available'),
        };
        $value = (float) $target->target;
        $figure = number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2).($target->indicator->measure === IndicatorMeasure::Percentage ? '%' : '');

        return implode(' · ', [
            $target->indicator->name,
            $committedIn,
            $target->project?->name ?? __('common.organisation_wide'),
            __('reports.target_of_value', ['value' => $figure]),
        ]);
    }

    /**
     * The target chosen in the form, if it still exists.
     */
    #[Computed]
    public function formTarget(): ?PlanIndicator
    {
        if ($this->reportTargetId === '') {
            return null;
        }

        return PlanIndicator::query()
            ->select(['id', 'indicator_id', 'source_type', 'source_id', 'target', 'period'])
            ->with([
                'indicator:id,name,measure,unit',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([AnnualPlan::class => [], Proposal::class => []]),
            ])
            ->find((int) $this->reportTargetId);
    }

    /**
     * The dates a figure for the chosen target may cover: the plan's year, or
     * the proposal's period (actual dates where they are known, else planned).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    protected function coverableWindow(?PlanIndicator $target): ?array
    {
        $source = $target?->source;

        return match (true) {
            $source instanceof AnnualPlan => [
                CarbonImmutable::create($source->year)->startOfYear(),
                CarbonImmutable::create($source->year)->endOfYear(),
            ],
            $source instanceof Proposal => [
                $source->actual_date_begin ?? $source->planned_date_begin,
                $source->actual_date_end ?? $source->planned_date_end,
            ],
            default => null,
        };
    }

    /**
     * The window the chosen target's figures must fall in, for the form hint.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function formWindow(): ?array
    {
        return $this->coverableWindow($this->formTarget);
    }

    public function updatedReportTargetId(): void
    {
        unset($this->formTarget);
    }

    public function openCreateModal(?int $targetId = null): void
    {
        Gate::authorize('create', Report::class);

        $this->resetReportForm();

        if ($targetId !== null && PlanIndicator::query()->whereKey($targetId)->exists()) {
            $this->reportTargetId = (string) $targetId;
        }

        $this->showReportModal = true;
    }

    public function openEditModal(int $reportId): void
    {
        $report = Report::query()
            ->select([
                'id', 'user_id', 'status', 'plan_indicator_id', 'planned_date', 'period_start', 'period_end', 'value',
                'narrative_report_notes', 'executive_summary', 'key_achievements', 'challenges',
                'lessons_learned', 'next_period_plans',
            ])
            ->findOrFail($reportId);

        if (! $report->isEditable()) {
            Flux::toast(
                variant: 'danger',
                text: __('reports.only_draft_reports_can_be_edited_return_it_to_draft_on_the_r'),
            );

            return;
        }

        Gate::authorize('update', $report);

        $this->resetReportForm();
        $this->editingReportId = $report->id;
        $this->reportTargetId = (string) $report->plan_indicator_id;
        $this->plannedDate = $report->planned_date?->toDateString() ?? '';
        $this->periodStart = $report->period_start?->toDateString() ?? '';
        $this->periodEnd = $report->period_end?->toDateString() ?? '';
        $this->value = (string) $report->value;
        $this->narrativeNotes = $report->narrative_report_notes ?? '';
        $this->executiveSummary = $report->executive_summary ?? '';
        $this->keyAchievements = $report->key_achievements ?? '';
        $this->challenges = $report->challenges ?? '';
        $this->lessonsLearned = $report->lessons_learned ?? '';
        $this->nextPeriodPlans = $report->next_period_plans ?? '';
        $this->showReportModal = true;
    }

    public function saveReport(): void
    {
        foreach (['narrativeNotes', 'executiveSummary', 'keyAchievements', 'challenges', 'lessonsLearned', 'nextPeriodPlans'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        $isCreate = $this->editingReportId === null;

        if ($isCreate) {
            Gate::authorize('create', Report::class);
        }

        $report = $isCreate
            ? new Report
            : Report::query()->select(['id', 'status', 'user_id'])->findOrFail($this->editingReportId);

        if (! $isCreate && ! $report->isEditable()) {
            $this->closeReportModal();

            Flux::toast(
                variant: 'danger',
                text: __('reports.only_draft_reports_can_be_edited_return_it_to_draft_on_the_r'),
            );

            return;
        }

        if (! $isCreate) {
            Gate::authorize('update', $report);
        }

        unset($this->formTarget);
        $target = $this->formTarget;
        $isPercentage = $target?->indicator?->measure === IndicatorMeasure::Percentage;

        // periodEnd's after_or_equal comparison is only meaningful once
        // periodStart is actually set — an empty periodStart cannot be parsed
        // as a date, so the rule is added only when there is something to
        // compare against.
        $periodEndRules = ['nullable', 'date'];

        if ($this->periodStart !== '') {
            $periodEndRules[] = 'after_or_equal:periodStart';
        }

        $validated = $this->validate([
            'reportTargetId' => ['required', 'integer', Rule::exists(PlanIndicator::class, 'id')],
            'plannedDate' => ['required', 'date'],
            'periodStart' => ['nullable', 'date'],
            'periodEnd' => $periodEndRules,
            // A percentage indicator reports a level out of 100.
            'value' => ['required', 'numeric', 'min:0', $isPercentage ? 'max:100' : 'max:9999999999.99'],
            'narrativeNotes' => ['nullable', 'string'],
            'executiveSummary' => ['nullable', 'string'],
            'keyAchievements' => ['nullable', 'string'],
            'challenges' => ['nullable', 'string'],
            'lessonsLearned' => ['nullable', 'string'],
            'nextPeriodPlans' => ['nullable', 'string'],
        ], [
            'reportTargetId.required' => __('reports.select_the_target_this_reports_against'),
            'plannedDate.required' => __('reports.set_the_period_this_figure_covers'),
            'value.required' => __('reports.enter_the_reported_value'),
            'value.min' => __('reports.a_reported_value_cannot_be_negative'),
            'value.max' => $isPercentage ? __('reports.a_percentage_cannot_exceed_100') : __('reports.the_reported_value_is_too_large_to_record'),
            'periodEnd.after_or_equal' => __('reports.the_narrative_window_end_cannot_be_before_its_start'),
        ]);

        $window = $this->coverableWindow($target);

        if ($window === null) {
            $this->addError('reportTargetId', __('targets.the_selected_source_is_no_longer_available'));

            return;
        }

        // A figure belongs to the plan year or proposal period it was committed for.
        $covered = CarbonImmutable::parse($validated['plannedDate']);

        if ($covered->lessThan($window[0]->startOfDay()) || $covered->greaterThan($window[1]->endOfDay())) {
            $this->addError('plannedDate', __('reports.the_period_must_fall_within', [
                'from' => $window[0]->translatedFormat(__('formats.date')),
                'to' => $window[1]->translatedFormat(__('formats.date')),
            ]));

            return;
        }

        $attributes = [
            'plan_indicator_id' => (int) $validated['reportTargetId'],
            'planned_date' => $validated['plannedDate'],
            'period_start' => $validated['periodStart'] === '' ? null : $validated['periodStart'],
            'period_end' => $validated['periodEnd'] === '' ? null : $validated['periodEnd'],
            'value' => $validated['value'],
            'narrative_report_notes' => $validated['narrativeNotes'] === '' ? null : $validated['narrativeNotes'],
            'executive_summary' => $validated['executiveSummary'] === '' ? null : $validated['executiveSummary'],
            'key_achievements' => $validated['keyAchievements'] === '' ? null : $validated['keyAchievements'],
            'challenges' => $validated['challenges'] === '' ? null : $validated['challenges'],
            'lessons_learned' => $validated['lessonsLearned'] === '' ? null : $validated['lessonsLearned'],
            'next_period_plans' => $validated['nextPeriodPlans'] === '' ? null : $validated['nextPeriodPlans'],
        ];

        // Authorship is set once. reports.user_id RESTRICTs on delete because
        // who reported a figure is part of the record.
        // status and submit_date are deliberately absent: they are written
        // only by the workflow actions on the detail page (submitForReview,
        // approve, returnToDraft), so the two can never disagree.
        if ($isCreate) {
            $attributes['user_id'] = (int) auth()->id();
        }

        $report->fill($attributes)->save();

        $this->closeReportModal();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('reports.report_recorded') : __('reports.report_updated'),
        );

        $this->reportSaved($report, $isCreate);
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    protected function resetReportForm(): void
    {
        $this->resetValidation();
        $this->editingReportId = null;
        $this->reportTargetId = '';
        $this->plannedDate = '';
        $this->periodStart = '';
        $this->periodEnd = '';
        $this->value = '';
        $this->narrativeNotes = '';
        $this->executiveSummary = '';
        $this->keyAchievements = '';
        $this->challenges = '';
        $this->lessonsLearned = '';
        $this->nextPeriodPlans = '';
        unset($this->formTarget);
    }

    public function confirmDeleteReport(int $reportId): void
    {
        $report = Report::query()->findOrFail($reportId);

        if ($report->isEditable()) {
            Gate::authorize('delete', $report);
        }

        $this->deletingReportId = $reportId;
        $this->showDeleteReportModal = true;
    }

    public function cancelDeleteReport(): void
    {
        $this->deletingReportId = null;
        $this->showDeleteReportModal = false;
    }

    /**
     * Delete a draft report and the evidence uploaded with it.
     *
     * Only a draft: a reviewed or approved figure is part of the record. The
     * report_files rows cascade in the database, but their files on disk do
     * not, so those are removed here after the rows are gone.
     */
    public function deleteReport(): void
    {
        if ($this->deletingReportId === null) {
            return;
        }

        $report = Report::query()->with('files:id,report_id,disk,path,original_name')->findOrFail($this->deletingReportId);

        if (! $report->isEditable()) {
            $this->cancelDeleteReport();

            Flux::toast(variant: 'danger', text: __('reports.only_draft_reports_can_be_deleted'));

            return;
        }

        Gate::authorize('delete', $report);

        $stored = $report->files->filter(fn ($file): bool => $file->original_name !== null);

        DB::transaction(fn () => $report->delete());

        foreach ($stored as $file) {
            rescue(fn () => Storage::disk($file->disk)->delete($file->path), report: true);
        }

        $this->cancelDeleteReport();

        Flux::toast(variant: 'success', text: __('reports.report_deleted'));

        $this->reportDeleted();
    }
}
