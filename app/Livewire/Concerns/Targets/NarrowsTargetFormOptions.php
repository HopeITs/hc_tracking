<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Targets;

use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The target form's options, narrowed by the results chain: the chosen
 * indicator, the sources and projects that fit it, and the hooks that drop a
 * choice that no longer fits; plus the project list the header filter reads.
 *
 * Used by targets/index only. Reads the form properties ManagesTargetForm
 * declares. ManagesTargetForm unsets these computed values;
 * FiltersTargets::updatedTargetSourceType() unsets sourceOptions and
 * formProjectOptions and calls syncFormProject().
 *
 * @property string $targetSourceType
 * @property string $targetSourceId
 * @property string $targetIndicatorId
 * @property string $targetProjectId
 * @property string $targetPeriod
 * @property ?int $editingTargetId
 * @property-read Indicator|null $formIndicator
 * @property-read array<int, array{id: int, label: string}> $sourceOptions
 * @property-read Collection<int, Project> $formProjectOptions
 */
trait NarrowsTargetFormOptions
{
    /**
     * The indicator chosen in the form, with the result it measures.
     */
    #[Computed]
    public function formIndicator(): ?Indicator
    {
        if ($this->targetIndicatorId === '') {
            return null;
        }

        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'frequency'])
            ->with(['result:id,statement,program_id', 'project:id,name'])
            ->find((int) $this->targetIndicatorId);
    }

    /**
     * The sources selectable under the currently chosen source type.
     *
     * An annual plan belongs to one program, so once the indicator's result
     * sits in a program only that program's plans are offered.
     *
     * @return array<int, array{id: int, label: string}>
     */
    #[Computed]
    public function sourceOptions(): array
    {
        if ($this->targetSourceType === 'proposal') {
            return Proposal::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'label' => $proposal->name])
                ->all();
        }

        $programId = $this->formIndicator?->result?->program_id;

        return AnnualPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->with('program:id')
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderByDesc('year')
            ->get()
            ->map(fn (AnnualPlan $plan): array => [
                'id' => $plan->id,
                'label' => __('common.fy_year_program', ['year' => $plan->year, 'program' => $plan->program->name]),
            ])
            ->all();
    }

    /** @return Collection<int, Indicator> */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'measure'])
            ->with('result:id,statement')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Projects that may deliver the target being committed.
     *
     * An indicator scoped to one project allows only that project. Otherwise
     * an annual-plan target is delivered inside the plan's program, and a
     * proposal target by a project the proposal funds.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function formProjectOptions(): Collection
    {
        $indicator = $this->formIndicator;
        $sourceId = $this->targetSourceId === '' ? null : (int) $this->targetSourceId;

        return Project::query()
            ->select(['id', 'name', 'program_id'])
            ->when($indicator?->project_id !== null, fn ($query) => $query->whereKey($indicator->project_id))
            ->when($sourceId !== null && $this->targetSourceType === 'annual_plan', fn ($query) => $query
                ->whereIn('program_id', AnnualPlan::query()->select('program_id')->whereKey($sourceId)))
            ->when($sourceId !== null && $this->targetSourceType === 'proposal', fn ($query) => $query
                ->whereIn('id', DB::table('project_proposal')->select('project_id')->where('proposal_id', $sourceId)))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Choosing an indicator narrows the plans and projects that fit it: a
     * project-scoped indicator fixes the project, and a source or project
     * that no longer fits is dropped rather than carried to the save.
     */
    public function updatedTargetIndicatorId(): void
    {
        unset($this->formIndicator, $this->sourceOptions, $this->formProjectOptions);

        if ($this->targetSourceId !== '' && ! collect($this->sourceOptions)->contains('id', (int) $this->targetSourceId)) {
            $this->targetSourceId = '';
        }

        // A new target reports on the indicator's own cadence unless told otherwise.
        if ($this->editingTargetId === null && $this->formIndicator !== null) {
            $this->targetPeriod = $this->formIndicator->frequency->value;
        }

        $this->syncFormProject();
    }

    public function updatedTargetSourceId(): void
    {
        unset($this->formProjectOptions);

        $this->syncFormProject();
    }

    /**
     * Keep the chosen project inside the options that fit, and fill it in
     * when the indicator leaves exactly one.
     */
    protected function syncFormProject(): void
    {
        $options = $this->formProjectOptions;

        if ($this->targetProjectId !== '' && ! $options->contains('id', (int) $this->targetProjectId)) {
            $this->targetProjectId = '';
        }

        if ($this->formIndicator?->project_id !== null && $options->count() === 1) {
            $this->targetProjectId = (string) $options->first()->id;
        }
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }
}
