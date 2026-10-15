<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The indicator dialog on indicators/index: create, edit and delete an
 * indicator, with the project picker limited to the result's program and
 * the measure locked once targets exist.
 *
 * Used by indicators/index only. The page provides forgetIndicators() and
 * WithPagination's resetPage().
 *
 * @property-read Collection<int, Project> $projectOptions  #[Computed] projectOptions()
 */
trait ManagesIndicatorForm
{
    public bool $showIndicatorModal = false;

    #[Locked]
    public ?int $editingIndicatorId = null;

    public string $name = '';

    public string $indicatorResultId = '';

    public string $indicatorMeasure = 'numeric';

    public string $unit = '';

    public string $baseline = '';

    public string $target = '';

    public string $frequency = 'quarterly';

    public string $indicatorProjectId = '';

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingIndicatorId = null;

    /** @return Collection<int, Result> */
    #[Computed]
    public function resultOptions(): Collection
    {
        return Result::query()->select(['id', 'statement', 'type'])->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * The program of the result chosen in the form, or null when the result
     * belongs to none (or none is chosen yet).
     */
    protected function formResultProgramId(): ?int
    {
        if ($this->indicatorResultId === '') {
            return null;
        }

        return Result::query()->whereKey((int) $this->indicatorResultId)->value('program_id');
    }

    /**
     * Projects an indicator on the chosen result may be scoped to: those
     * delivering the result's program, or every project when the result sits
     * in no program.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        $programId = $this->formResultProgramId();

        return Project::query()
            ->select(['id', 'name', 'program_id'])
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * A project chosen for the old result may not deliver the new one's
     * program, so it is dropped rather than carried to the save.
     */
    public function updatedIndicatorResultId(): void
    {
        unset($this->projectOptions);

        if ($this->indicatorProjectId !== '' && ! $this->projectOptions->contains('id', (int) $this->indicatorProjectId)) {
            $this->indicatorProjectId = '';
        }
    }

    /**
     * A percentage is always read against 100, so it carries no unit of its own.
     */
    public function updatedIndicatorMeasure(): void
    {
        if ($this->indicatorMeasure === IndicatorMeasure::Percentage->value) {
            $this->unit = '';
        }
    }

    /**
     * Whether this indicator may be deleted.
     *
     * Refused once any target exists: plan_indicators cascades from indicators,
     * and reports and report_files cascade from those.
     */
    protected function canDelete(int $indicatorId): bool
    {
        $indicator = Indicator::query()->select(['id'])->withCount('planIndicators')->find($indicatorId);

        return $indicator !== null && $indicator->plan_indicators_count === 0;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('indicators.create');

        $this->resetIndicatorForm();
        $this->showIndicatorModal = true;
    }

    public function openEditModal(int $indicatorId): void
    {
        Gate::authorize('indicators.update');

        $indicator = Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency'])
            ->findOrFail($indicatorId);

        $this->resetValidation();
        $this->editingIndicatorId = $indicator->id;
        $this->name = $indicator->name;
        $this->indicatorResultId = (string) $indicator->result_id;
        $this->indicatorMeasure = $indicator->measure->value;
        $this->unit = (string) ($indicator->unit ?? '');
        $this->baseline = $indicator->baseline === null ? '' : (string) $indicator->baseline;
        $this->target = $indicator->target === null ? '' : (string) $indicator->target;
        $this->frequency = $indicator->frequency->value;
        $this->indicatorProjectId = (string) ($indicator->project_id ?? '');
        $this->showIndicatorModal = true;
    }

    public function saveIndicator(): void
    {
        Gate::authorize($this->editingIndicatorId === null ? 'indicators.create' : 'indicators.update');

        $this->name = trim($this->name);
        $this->unit = trim($this->unit);
        $isPercentage = $this->indicatorMeasure === IndicatorMeasure::Percentage->value;
        $valueCeiling = $isPercentage ? 'max:100' : 'max:9999999999.99';

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'indicatorResultId' => ['required', 'integer', Rule::exists(Result::class, 'id')],
            'indicatorMeasure' => ['required', new Enum(IndicatorMeasure::class)],
            'unit' => ['nullable', 'string', 'max:50'],
            'baseline' => ['nullable', 'numeric', 'min:0', $valueCeiling],
            'target' => ['nullable', 'numeric', 'min:0', $valueCeiling],
            'frequency' => ['required', new Enum(Period::class)],
            'indicatorProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'name.required' => __('indicators.enter_the_indicator_name'),
            'indicatorResultId.required' => __('indicators.select_the_result_this_indicator_measures'),
            'indicatorMeasure.required' => __('indicators.choose_whether_this_counts_or_is_a_percentage'),
            'frequency.required' => __('common.choose_how_often_this_is_checked'),
        ] + ($isPercentage ? [
            'baseline.max' => __('indicators.a_percentage_cannot_exceed_100'),
            'target.max' => __('indicators.a_percentage_cannot_exceed_100'),
        ] : []));

        $indicator = $this->editingIndicatorId === null
            ? new Indicator
            : Indicator::query()->findOrFail($this->editingIndicatorId);

        $result = Result::query()->select(['id', 'program_id', 'proposal_id'])->findOrFail((int) $validated['indicatorResultId']);
        $projectId = $validated['indicatorProjectId'] === '' || $validated['indicatorProjectId'] === null
            ? null
            : (int) $validated['indicatorProjectId'];

        if ($projectId !== null && $result->program_id !== null
            && Project::query()->whereKey($projectId)->value('program_id') !== $result->program_id) {
            $this->addError('indicatorProjectId', __('indicators.this_project_does_not_deliver_the_result_program'));

            return;
        }

        $indicator->fill([
            'name' => $validated['name'],
            'result_id' => $result->id,
            'measure' => $validated['indicatorMeasure'],
            'unit' => $isPercentage || $validated['unit'] === '' || $validated['unit'] === null ? null : $validated['unit'],
            'baseline' => $validated['baseline'] === '' || $validated['baseline'] === null ? null : $validated['baseline'],
            'target' => $validated['target'] === '' || $validated['target'] === null ? null : $validated['target'],
            'frequency' => $validated['frequency'],
            'project_id' => $projectId,
        ]);

        if ($indicator->exists && ! $this->changeKeepsTargetsInChain($indicator, $result)) {
            return;
        }

        $indicator->save();

        $wasRecentlyCreated = $indicator->wasRecentlyCreated;

        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
        $this->forgetIndicators();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('indicators.indicator_created') : __('indicators.indicator_updated'),
        );
    }

    /**
     * Whether an edit leaves every target on this indicator fitting its chain.
     *
     * The measure is locked once targets exist: it decides how their reports
     * add up (summed or latest), so switching it would silently rewrite every
     * achievement figure. A new result or project is checked against each
     * target with PlanIndicator::chainIssuesFor(), and refused only when it
     * introduces a problem the target did not already have — a row that was
     * already out of step must not block an unrelated fix.
     */
    protected function changeKeepsTargetsInChain(Indicator $indicator, Result $result): bool
    {
        $targets = PlanIndicator::query()
            ->where('indicator_id', $indicator->id)
            ->with([
                'project:id,program_id',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Proposal::class => ['projects:id']]),
            ])
            ->get();

        if ($targets->isEmpty()) {
            return true;
        }

        if ($indicator->isDirty('measure')) {
            $this->addError('indicatorMeasure', __('indicators.the_measure_is_locked_while_targets_exist'));

            return false;
        }

        if (! $indicator->isDirty(['result_id', 'project_id'])) {
            return true;
        }

        $before = Indicator::query()->with('result:id,program_id,proposal_id')->findOrFail($indicator->id);
        $after = (clone $indicator)->setRelation('result', $result);

        foreach ($targets as $target) {
            $introduced = array_diff(
                PlanIndicator::chainIssuesFor($target->source, $after, $target->project),
                PlanIndicator::chainIssuesFor($target->source, $before, $target->project),
            );

            if ($introduced !== []) {
                $this->addError(
                    $indicator->isDirty('result_id') ? 'indicatorResultId' : 'indicatorProjectId',
                    __('indicators.this_change_would_break_a_committed_target', ['issue' => __(reset($introduced))]),
                );

                return false;
            }
        }

        return true;
    }

    public function confirmDelete(int $indicatorId): void
    {
        Gate::authorize('indicators.delete');

        $this->deletingIndicatorId = $indicatorId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingIndicatorId = null;
        $this->showDeleteModal = false;
    }

    public function deleteIndicator(): void
    {
        Gate::authorize('indicators.delete');

        if ($this->deletingIndicatorId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingIndicatorId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('indicators.this_indicator_carries_targets_remove_them_first_deleting_it'),
            );

            return;
        }

        Indicator::query()->findOrFail($this->deletingIndicatorId)->delete();

        $this->cancelDelete();
        $this->forgetIndicators();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('indicators.indicator_deleted'));
    }

    public function closeIndicatorModal(): void
    {
        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
    }

    protected function resetIndicatorForm(): void
    {
        $this->resetValidation();
        $this->editingIndicatorId = null;
        $this->name = '';
        $this->indicatorResultId = '';
        $this->indicatorMeasure = 'numeric';
        $this->unit = '';
        $this->baseline = '';
        $this->target = '';
        $this->frequency = 'quarterly';
        $this->indicatorProjectId = '';
    }

    /**
     * Drop every cached read after a write; the page's own method.
     */
    abstract protected function forgetIndicators(): void;
}
