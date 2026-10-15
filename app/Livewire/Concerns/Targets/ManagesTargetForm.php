<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Targets;

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;

/**
 * The target form: its properties, the open, save and close actions, the
 * delete confirmation, and the source label the list prints.
 *
 * Used by targets/index only. The page's mount() opens the form from
 * `?create=1`. Its options and syncFormProject() live in
 * NarrowsTargetFormOptions (split off to keep each trait under 400 lines);
 * this trait unsets those computed options on open and reset. The page
 * provides resetPage() (WithPagination).
 *
 * @property-read Indicator|null $formIndicator
 * @property-read array<int, array{id: int, label: string}> $sourceOptions
 * @property-read Collection<int, Project> $formProjectOptions
 */
trait ManagesTargetForm
{
    /**
     * The only two sources a target may be committed in.
     *
     * These are the morph map keys registered in AppServiceProvider, not class
     * names: plan_indicators.source_type is VARCHAR(50) and
     * Relation::enforceMorphMap() is global.
     */
    private const SOURCE_TYPES = ['annual_plan', 'proposal'];

    public bool $showTargetModal = false;

    #[Locked]
    public ?int $editingTargetId = null;

    #[Locked]
    public ?int $deletingTargetId = null;

    public bool $showDeleteModal = false;

    public string $targetSourceType = 'annual_plan';

    public string $targetSourceId = '';

    public string $targetIndicatorId = '';

    public string $targetValue = '';

    public string $targetProjectId = '';

    public string $targetPeriod = 'quarterly';

    /**
     * Whether this target may be deleted.
     *
     * Refused once reported: reports cascade from plan_indicators, and
     * report_files cascade from reports. A measurement is not discarded to tidy
     * up a target.
     */
    protected function canDelete(int $targetId): bool
    {
        $target = PlanIndicator::query()->select(['id'])->withCount('reports')->find($targetId);

        return $target !== null && $target->reports_count === 0;
    }

    /**
     * A human label for a target's source.
     *
     * Returns null when the source no longer exists. source_id carries no
     * foreign key, so a raw delete of a plan or proposal leaves this dangling
     * and the caller must render an explicit fallback.
     */
    protected function sourceLabel(PlanIndicator $target): ?string
    {
        $source = $target->source;

        if ($source === null) {
            return null;
        }

        if ($source instanceof AnnualPlan) {
            return __('common.fy_year', ['year' => $source->year]);
        }

        if ($source instanceof Proposal) {
            return $source->name;
        }

        return null;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('targets.create');

        $this->resetTargetForm();
        $this->showTargetModal = true;
    }

    public function openEditModal(int $targetId): void
    {
        Gate::authorize('targets.update');

        $target = PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->findOrFail($targetId);

        $this->resetValidation();
        $this->editingTargetId = $target->id;
        $this->targetSourceType = $target->source_type;
        $this->targetSourceId = (string) $target->source_id;
        $this->targetIndicatorId = (string) $target->indicator_id;
        $this->targetValue = (string) $target->target;
        $this->targetProjectId = (string) ($target->project_id ?? '');
        $this->targetPeriod = $target->period->value;
        unset($this->formIndicator, $this->sourceOptions, $this->formProjectOptions);
        $this->showTargetModal = true;
    }

    public function saveTarget(): void
    {
        Gate::authorize($this->editingTargetId === null ? 'targets.create' : 'targets.update');

        $validated = $this->validate([
            'targetSourceType' => ['required', Rule::in(self::SOURCE_TYPES)],
            'targetSourceId' => ['required', 'integer'],
            'targetIndicatorId' => ['required', 'integer', Rule::exists(Indicator::class, 'id')],
            'targetValue' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'targetProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
            'targetPeriod' => ['required', new Enum(Period::class)],
        ], [
            'targetSourceType.required' => __('targets.choose_where_this_target_was_committed'),
            'targetSourceType.in' => __('targets.a_target_can_only_be_committed_in_an_annual_plan_or_a_propos'),
            'targetSourceId.required' => __('targets.select_the_annual_plan_or_proposal'),
            'targetIndicatorId.required' => __('targets.select_the_indicator_being_targeted'),
            'targetValue.required' => __('targets.enter_the_target_value'),
            'targetValue.min' => __('targets.a_target_cannot_be_negative'),
            'targetValue.max' => __('targets.the_target_value_is_too_large_to_record'),
        ]);

        // The chosen source must actually exist under the chosen type. A single
        // exists rule cannot express this, because the table depends on the type.
        $source = $validated['targetSourceType'] === 'proposal'
            ? Proposal::query()->with('projects:id')->find((int) $validated['targetSourceId'])
            : AnnualPlan::query()->find((int) $validated['targetSourceId']);

        if ($source === null) {
            $this->addError('targetSourceId', __('targets.the_selected_source_is_no_longer_available'));

            return;
        }

        $indicator = Indicator::query()->with('result:id,program_id,proposal_id')->findOrFail((int) $validated['targetIndicatorId']);
        $projectId = $validated['targetProjectId'] === '' || $validated['targetProjectId'] === null
            ? null
            : (int) $validated['targetProjectId'];
        $project = $projectId === null ? null : Project::query()->select(['id', 'program_id'])->find($projectId);

        // A percentage is read against 100; a target above it can never be met.
        if ($indicator->measure === IndicatorMeasure::Percentage && (float) $validated['targetValue'] > 100) {
            $this->addError('targetValue', __('targets.a_percentage_target_cannot_exceed_100'));

            return;
        }

        $target = $this->editingTargetId === null
            ? new PlanIndicator
            : PlanIndicator::query()->withCount('reports')->findOrFail($this->editingTargetId);

        $target->fill([
            // The map key, never the class name.
            'source_type' => $validated['targetSourceType'],
            'source_id' => $source->id,
            'indicator_id' => $indicator->id,
            'target' => $validated['targetValue'],
            'project_id' => $projectId,
            'period' => $validated['targetPeriod'],
        ]);

        // Reports were filed against this indicator in this plan or proposal;
        // moving the target would re-label every one of them.
        if ($target->exists && $target->reports_count > 0 && $target->isDirty(['indicator_id', 'source_type', 'source_id'])) {
            $this->addError('targetIndicatorId', __('targets.the_indicator_and_source_are_locked_once_reported_against'));

            return;
        }

        $issues = PlanIndicator::chainIssuesFor($source, $indicator, $project);

        // Only what this edit introduces: a row already out of step can still
        // have its value or period corrected.
        if ($target->exists && $issues !== []) {
            $original = PlanIndicator::query()
                ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Proposal::class => ['projects:id']]), 'project:id,program_id'])
                ->findOrFail($target->id);
            $issues = array_values(array_diff($issues, PlanIndicator::chainIssuesFor($original->source, $indicator, $original->project)));
        }

        if ($issues !== []) {
            foreach ($issues as $issue) {
                $this->addError($issue === 'targets.issue_plan_program_differs_from_result' ? 'targetSourceId' : 'targetProjectId', __($issue));
            }

            return;
        }

        $duplicate = PlanIndicator::query()
            ->where('source_type', $target->source_type)
            ->where('source_id', $target->source_id)
            ->where('indicator_id', $target->indicator_id)
            ->where(fn ($query) => $projectId === null ? $query->whereNull('project_id') : $query->where('project_id', $projectId))
            ->when($target->exists, fn ($query) => $query->whereKeyNot($target->id))
            ->exists();

        if ($duplicate) {
            $this->addError('targetIndicatorId', __('targets.this_indicator_already_has_a_target_here'));

            return;
        }

        $target->save();

        $wasRecentlyCreated = $target->wasRecentlyCreated;

        $this->showTargetModal = false;
        $this->resetTargetForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('targets.target_committed') : __('targets.target_updated'),
        );
    }

    public function confirmDelete(int $targetId): void
    {
        Gate::authorize('targets.delete');

        $this->deletingTargetId = $targetId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingTargetId = null;
        $this->showDeleteModal = false;
    }

    public function deleteTarget(): void
    {
        Gate::authorize('targets.delete');

        if ($this->deletingTargetId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingTargetId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('targets.this_target_has_been_reported_against_deleting_it_would_remo'),
            );

            return;
        }

        PlanIndicator::query()->findOrFail($this->deletingTargetId)->delete();

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('targets.target_deleted'));
    }

    public function closeTargetModal(): void
    {
        $this->showTargetModal = false;
        $this->resetTargetForm();
    }

    protected function resetTargetForm(): void
    {
        $this->resetValidation();
        $this->editingTargetId = null;
        $this->targetSourceType = 'annual_plan';
        $this->targetSourceId = '';
        $this->targetIndicatorId = '';
        $this->targetValue = '';
        $this->targetProjectId = '';
        $this->targetPeriod = 'quarterly';
        unset($this->formIndicator, $this->sourceOptions, $this->formProjectOptions);
    }
}
