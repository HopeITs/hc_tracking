<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Targets;

use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The target form's options, narrowed by the results chain: the chosen
 * indicator, the sources and location rows that fit it, and the hooks that
 * drop a choice that no longer fits; plus the row list the header filter reads.
 *
 * Used by targets/index only. Reads the form properties ManagesTargetForm
 * declares. ManagesTargetForm unsets these computed values;
 * FiltersTargets::updatedTargetSourceType() unsets sourceOptions and calls
 * syncFormLocation().
 *
 * @property string $targetSourceType
 * @property string $targetSourceId
 * @property string $targetIndicatorId
 * @property string $targetLocationId
 * @property string $targetPeriod
 * @property ?int $editingTargetId
 * @property-read Indicator|null $formIndicator
 * @property-read array<int, array{id: int, label: string}> $sourceOptions
 * @property-read list<array{id: int, label: string}> $formLocationOptions
 * @property-read list<array{id: int, label: string}> $locationOptions
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

        return $this->targetIndicators()
            ->select(['id', 'name', 'result_id', 'project_id', 'proposal_location_id', 'measure', 'unit', 'frequency'])
            ->with(['result:id,statement,program_id', 'location', 'location.city', 'location.program', 'location.centers'])
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
        return $this->targetIndicators()
            ->select(['id', 'name', 'result_id', 'measure'])
            ->with('result:id,statement')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Choosing an indicator narrows the plans and rows that fit it: a
     * row-scoped indicator fixes the row, and a source or row that no longer
     * fits is dropped rather than carried to the save.
     */
    public function updatedTargetIndicatorId(): void
    {
        unset($this->formIndicator, $this->sourceOptions);

        if ($this->targetSourceId !== '' && ! collect($this->sourceOptions)->contains('id', (int) $this->targetSourceId)) {
            $this->targetSourceId = '';
        }

        // A new target reports on the indicator's own cadence unless told otherwise.
        if ($this->editingTargetId === null && $this->formIndicator !== null) {
            $this->targetPeriod = $this->formIndicator->frequency->value;
        }

        $this->syncFormLocation();
    }

    public function updatedTargetSourceId(): void
    {
        $this->syncFormLocation();
    }

    /**
     * The location rows (city · program) a target may be committed at
     * (Y-10, D-91; Y-16, D-93).
     *
     * A proposal target: the rows of the chosen proposal. An annual-plan
     * target: the rows of the plan's program, since a plan belongs to one
     * program — the row-keyed twin of "a project delivering an annual-plan
     * target must sit in the plan's program". An indicator scoped to one row
     * allows only that row.
     *
     * @return list<array{id: int, label: string}>
     */
    #[Computed]
    public function formLocationOptions(): array
    {
        if ($this->targetSourceId === '') {
            return [];
        }

        $sourceId = (int) $this->targetSourceId;
        $scopedTo = $this->formIndicator?->proposal_location_id;

        $rows = ProposalLocation::query()
            ->with('program')
            ->when($this->targetSourceType === 'proposal', fn ($query) => $query->where('proposal_id', $sourceId))
            ->when($this->targetSourceType === 'annual_plan', fn ($query) => $query
                ->whereNotNull('program_id')
                ->whereIn('program_id', AnnualPlan::query()->select('program_id')->whereKey($sourceId)))
            ->when($scopedTo !== null, fn ($query) => $query->whereKey($scopedTo))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $labels = ProposalLocation::placeLabels($rows);

        return $rows->map(fn (ProposalLocation $row): array => ['id' => $row->id, 'label' => $labels[$row->id]])->values()->all();
    }

    /**
     * Keep the chosen row inside the options that fit, and fill it in when the
     * indicator leaves exactly one.
     */
    protected function syncFormLocation(): void
    {
        unset($this->formLocationOptions);

        $options = $this->formLocationOptions;

        if ($this->targetLocationId !== '' && ! collect($options)->contains('id', (int) $this->targetLocationId)) {
            $this->targetLocationId = '';
        }

        if ($this->formIndicator?->proposal_location_id !== null && count($options) === 1) {
            $this->targetLocationId = (string) $options[0]['id'];
        }
    }

    /**
     * Every location row, for the directory's header filter (Y-16).
     *
     * @return list<array{id: int, label: string}>
     */
    #[Computed]
    public function locationOptions(): array
    {
        $rows = ProposalLocation::query()->with(['proposal:id,name', 'program'])->get();
        $labels = ProposalLocation::placeLabels($rows);

        return $rows
            ->sortBy(fn (ProposalLocation $row): string => ($row->proposal->name ?? '').' '.$labels[$row->id])
            ->map(fn (ProposalLocation $row): array => [
                'id' => $row->id,
                'label' => ($row->proposal->name ?? '').' · '.$labels[$row->id],
            ])
            ->values()
            ->all();
    }
}
