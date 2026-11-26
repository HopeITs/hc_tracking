<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
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
 * indicator, with the location-row picker limited to the result's program and
 * the measure locked once targets exist.
 *
 * Y-16 (D-93): an instance is scoped to one place of a proposal, so the picker
 * offers location rows, not projects. `project_id` is left as it is on an
 * existing instance and null on a new one: the project writers still fill it
 * until Y-18, and nothing reads it here any more.
 *
 * Used by indicators/index and the in-place form on a results framework
 * (components/indicators/quick-form). The host provides forgetIndicators()
 * and resetPage() (WithPagination's on the directory).
 *
 * @property-read Collection<int, ProposalLocation> $locationOptions  #[Computed] locationOptions()
 */
trait ManagesIndicatorForm
{
    public bool $showIndicatorModal = false;

    #[Locked]
    public ?int $editingIndicatorId = null;

    #[Locked]
    public ?int $editingIndicatorLibraryId = null;

    public string $name = '';

    public string $indicatorResultId = '';

    public string $indicatorMeasure = 'numeric';

    public string $unit = '';

    public string $baseline = '';

    public string $target = '';

    public string $frequency = 'quarterly';

    public string $indicatorLocationId = '';

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingIndicatorId = null;

    /** @return Collection<int, Result> */
    #[Computed]
    public function resultOptions(): Collection
    {
        return Result::query()
            ->when($this->indicatorFormProposalId() !== null, fn ($query) => $query->proposalFramework($this->indicatorFormProposalId()))
            ->select(['id', 'statement', 'type', 'program_id'])->with('program:id')->orderBy('id')->get();
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
     * Location rows an indicator on the chosen result may be scoped to: the
     * rows of the result's program, or every row when the result sits in no
     * program (Y-16, D-93).
     *
     * A row still waiting for its program is never offered: it has no place
     * yet, so nothing can be scoped to it.
     *
     * @return Collection<int, ProposalLocation>
     */
    #[Computed]
    public function locationOptions(): Collection
    {
        $programId = $this->formResultProgramId();

        return ProposalLocation::query()
            ->select(['id', 'proposal_id', 'city_id', 'program_id', 'city_wide'])
            ->whereNotNull('program_id')
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->with(['proposal:id,name', 'city', 'program', 'centers'])
            ->get()
            ->sortBy(fn (ProposalLocation $row): string => ($row->proposal->name ?? '').' '.$row->label())
            ->values();
    }

    /**
     * A row chosen for the old result may not sit in the new one's program,
     * so it is dropped rather than carried to the save.
     */
    public function updatedIndicatorResultId(): void
    {
        unset($this->locationOptions);

        if ($this->indicatorLocationId !== '' && ! $this->locationOptions->contains('id', (int) $this->indicatorLocationId)) {
            $this->indicatorLocationId = '';
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

    public function openEditModal(int $indicatorId): void
    {
        Gate::authorize('indicators.update');

        $indicator = Indicator::query()
            ->when($this->indicatorFormProposalId() !== null, fn ($query) => $query->whereHas('result', fn ($result) => $result->proposalFramework($this->indicatorFormProposalId())))
            ->select(['id', 'name', 'result_id', 'project_id', 'proposal_location_id', 'measure', 'unit', 'baseline', 'target', 'frequency', 'library_indicator_id'])
            ->findOrFail($indicatorId);

        $this->resetValidation();
        $this->editingIndicatorId = $indicator->id;
        $this->editingIndicatorLibraryId = $indicator->library_indicator_id;
        $this->name = $indicator->name;
        $this->indicatorResultId = (string) $indicator->result_id;
        $this->indicatorMeasure = $indicator->measure->value;
        $this->unit = (string) ($indicator->unit ?? '');
        $this->baseline = $indicator->baseline === null ? '' : (string) $indicator->baseline;
        $this->target = $indicator->target === null ? '' : (string) $indicator->target;
        $this->frequency = $indicator->frequency->value;
        $this->indicatorLocationId = (string) ($indicator->proposal_location_id ?? '');
        $this->showIndicatorModal = true;
    }

    public function saveIndicator(): void
    {
        Gate::authorize('indicators.update');
        abort_if($this->editingIndicatorId === null, 403);

        $indicator = Indicator::query()->with('result.proposal')->findOrFail($this->editingIndicatorId);
        if ($indicator->result->proposal !== null) {
            Gate::authorize('view', $indicator->result->proposal);
            abort_if($indicator->result->proposal->status->isTerminal(), 403);
        }

        if ($indicator->library_indicator_id !== null) {
            $this->name = $indicator->name;
            $this->indicatorMeasure = $indicator->measure->value;
            $this->unit = (string) $indicator->unit;
            $this->frequency = $indicator->frequency->value;
        }

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
            'indicatorLocationId' => ['nullable', 'integer', Rule::exists(ProposalLocation::class, 'id')],
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

        $result = Result::query()
            ->when($this->indicatorFormProposalId() !== null, fn ($query) => $query->proposalFramework($this->indicatorFormProposalId()))
            ->select(['id', 'program_id', 'proposal_id'])->findOrFail((int) $validated['indicatorResultId']);
        $locationId = $validated['indicatorLocationId'] === '' || $validated['indicatorLocationId'] === null
            ? null
            : (int) $validated['indicatorLocationId'];

        if ($locationId !== null && $result->program_id !== null
            && ProposalLocation::query()->whereKey($locationId)->value('program_id') !== $result->program_id) {
            $this->addError('indicatorLocationId', __('indicators.this_project_does_not_deliver_the_result_program'));

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
            'proposal_location_id' => $locationId,
        ]);

        if ($indicator->exists && ! $this->changeKeepsTargetsInChain($indicator, $result)) {
            return;
        }

        $indicator->save();

        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
        $this->forgetIndicators();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('indicators.indicator_updated'));
    }

    /**
     * Whether an edit leaves every target on this indicator fitting its chain.
     *
     * The measure is locked once targets exist: it decides how their reports
     * add up (summed or latest), so switching it would silently rewrite every
     * achievement figure. A new result or row is checked against each
     * target with PlanIndicator::chainIssuesForRow(), and refused only when it
     * introduces a problem the target did not already have — a row that was
     * already out of step must not block an unrelated fix.
     */
    protected function changeKeepsTargetsInChain(Indicator $indicator, Result $result): bool
    {
        $targets = PlanIndicator::query()
            ->where('indicator_id', $indicator->id)
            ->with([
                'location:id,proposal_id,program_id',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Proposal::class => []]),
            ])
            ->get();

        if ($targets->isEmpty()) {
            return true;
        }

        if ($indicator->isDirty('measure')) {
            $this->addError('indicatorMeasure', __('indicators.the_measure_is_locked_while_targets_exist'));

            return false;
        }

        if (! $indicator->isDirty(['result_id', 'proposal_location_id'])) {
            return true;
        }

        $before = Indicator::query()->with('result:id,program_id,proposal_id')->findOrFail($indicator->id);
        $after = (clone $indicator)->setRelation('result', $result);

        foreach ($targets as $target) {
            $introduced = array_diff(
                PlanIndicator::chainIssuesForRow($target->source, $after, $target->location),
                PlanIndicator::chainIssuesForRow($target->source, $before, $target->location),
            );

            if ($introduced !== []) {
                $this->addError(
                    $indicator->isDirty('result_id') ? 'indicatorResultId' : 'indicatorLocationId',
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

    /**
     * The name of the indicator the open delete dialog is about.
     */
    #[Computed]
    public function deletingIndicatorName(): ?string
    {
        return $this->deletingIndicatorId === null
            ? null
            : Indicator::query()->whereKey($this->deletingIndicatorId)->value('name');
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
        $this->editingIndicatorLibraryId = null;
        $this->resetValidation();
        $this->editingIndicatorId = null;
        $this->name = '';
        $this->indicatorResultId = '';
        $this->indicatorMeasure = 'numeric';
        $this->unit = '';
        $this->baseline = '';
        $this->target = '';
        $this->frequency = 'quarterly';
        $this->indicatorLocationId = '';
    }

    protected function indicatorFormProposalId(): ?int
    {
        return null;
    }

    /**
     * Drop every cached read after a write; the page's own method.
     */
    abstract protected function forgetIndicators(): void;
}
