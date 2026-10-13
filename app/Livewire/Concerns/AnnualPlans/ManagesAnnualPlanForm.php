<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\AnnualPlans;

use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use App\Models\Program;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The annual-plan form and its delete confirmation, and the refresh that
 * drops the matrix's memoised reads after a write.
 *
 * Used by annual-plans/index only. Reads the matrix members FiltersPlanMatrix
 * and BuildsPlanMatrix provide, and the page's #[Url] filter properties.
 *
 * @property array<int, int> $years
 * @property string $focusYear
 * @property-read array<int, int> $yearOptions
 * @property-read array<int, int> $yearColumns
 * @property-read int|null $activeYear
 * @property-read array<string, mixed>|null $yearRange
 * @property-read Collection<int, HrEmployee> $managerOptions
 * @property-read mixed $programRows
 * @property-read array<string, AnnualPlan> $planCells
 * @property-read array<int, array<string, int>> $planDelivery
 * @property-read array<int, array<string, int>> $yearStats
 * @property-read array<int, array<string, mixed>> $yearRail
 * @property-read array<string, mixed>|null $cockpit
 */
trait ManagesAnnualPlanForm
{
    public bool $showPlanModal = false;

    #[Locked]
    public ?int $editingPlanId = null;

    #[Locked]
    public ?int $deletingPlanId = null;

    /**
     * Whether the delete confirmation is on screen.
     *
     * Carried separately from deletingPlanId because the modal has to be bound
     * with wire:model: an :open expression is only read when the dialog is
     * first rendered, so a Livewire patch that flips it never opens the dialog.
     */
    public bool $showDeleteModal = false;

    public string $planProgramId = '';

    public string $planManagerId = '';

    public string $planYear = '';

    /**
     * Every program the plan form can be written against.
     *
     * The full list, not the filtered rows: a plan is created for a program
     * whether or not the current filters put it on screen.
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * The fiscal years the form offers.
     *
     * Built from the years already planned, plus this year and the next, so a
     * new fiscal year can be opened without a data change. The year being
     * edited — or the one carried in from a clicked cell — is folded in as
     * well, so a plan written for an older year stays selectable in its own
     * form. Reads the memoised yearOptions rather than the table, which is why
     * the form costs the matrix no extra read of annual_plans.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearFormOptions(): array
    {
        $thisYear = (int) now()->year;

        $years = [...$this->yearOptions, $thisYear, $thisYear + 1];

        if ($this->planYear !== '') {
            $years[] = (int) $this->planYear;
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /**
     * What the delete confirmation has to warn about.
     *
     * The plan's observer removes the targets committed in it, and the reports
     * filed against those targets go with them, so the count is named in the
     * dialog rather than discovered afterwards. Null while no delete is
     * pending, which keeps this read out of the matrix's query budget.
     *
     * @return array{plan: AnnualPlan, targets: int}|null
     */
    #[Computed]
    public function deletingPlan(): ?array
    {
        if ($this->deletingPlanId === null) {
            return null;
        }

        $plan = AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->with('program:id')
            ->withCount('planIndicators')
            ->find($this->deletingPlanId);

        if ($plan === null) {
            return null;
        }

        return ['plan' => $plan, 'targets' => $plan->plan_indicators_count];
    }

    /**
     * Open the form for a new plan.
     *
     * A gap in the grid passes its own program and fiscal year, so clicking an
     * empty cell writes the plan that cell is missing instead of asking for
     * coordinates the user already pointed at.
     */
    public function openCreateModal(?int $programId = null, ?int $year = null): void
    {
        Gate::authorize('create', AnnualPlan::class);

        $this->resetPlanForm();
        $this->planProgramId = $programId === null ? '' : (string) $programId;
        $this->planYear = $year === null ? '' : (string) $year;
        $this->showPlanModal = true;
    }

    public function openEditModal(int $planId): void
    {
        $plan = AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->findOrFail($planId);

        Gate::authorize('update', $plan);

        $this->resetValidation();
        $this->editingPlanId = $plan->id;
        $this->planProgramId = (string) $plan->program_id;
        $this->planManagerId = (string) $plan->manager_id;
        $this->planYear = (string) $plan->year;
        $this->showPlanModal = true;
    }

    /**
     * Write the plan.
     *
     * The (program_id, year) pair is unique in the database, so the pair is
     * validated here too: without that rule a second plan for the same year
     * surfaces as a driver error instead of a message on the field.
     */
    public function savePlan(): void
    {
        if ($this->editingPlanId === null) {
            Gate::authorize('create', AnnualPlan::class);
        } else {
            Gate::authorize('update', AnnualPlan::query()->findOrFail($this->editingPlanId));
        }

        $validated = $this->validate([
            'planProgramId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'planManagerId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'planYear' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
                Rule::unique(AnnualPlan::class, 'year')
                    ->where(fn ($query) => $query->where('program_id', (int) $this->planProgramId))
                    ->ignore($this->editingPlanId),
            ],
        ], [
            'planProgramId.required' => __('annual_plans.select_the_program_this_plan_covers'),
            'planProgramId.exists' => __('annual_plans.the_selected_program_is_no_longer_available'),
            'planManagerId.required' => __('annual_plans.select_the_manager_accountable_for_this_plan'),
            'planManagerId.exists' => __('annual_plans.the_selected_manager_is_no_longer_available'),
            'planYear.required' => __('annual_plans.select_the_fiscal_year_this_plan_covers'),
            'planYear.unique' => __('annual_plans.this_program_already_has_a_plan_for_that_fiscal_year'),
        ]);

        $plan = $this->editingPlanId === null
            ? new AnnualPlan
            : AnnualPlan::query()->findOrFail($this->editingPlanId);

        // A plan's own manager or its program's manager edits the plan but
        // does not hand it to another program or manager.
        $mayReassign = ! $plan->exists || Gate::allows('reassign', $plan);

        $plan->fill([
            'program_id' => $mayReassign ? (int) $validated['planProgramId'] : $plan->program_id,
            'manager_id' => $mayReassign ? (int) $validated['planManagerId'] : $plan->manager_id,
            'year' => (int) $validated['planYear'],
        ])->save();

        $wasRecentlyCreated = $plan->wasRecentlyCreated;

        // Read against the span the writer was looking at, before the write
        // drops the memoised columns.
        $landedOutsideTheVisibleSpan = ! in_array($plan->year, $this->yearColumns, true);

        $this->showPlanModal = false;
        $this->resetPlanForm();

        // A plan written for a year the filters exclude would be saved into an
        // empty screen: the toast would report success and nothing would
        // appear. The window opens to show the work instead.
        if ($landedOutsideTheVisibleSpan) {
            $this->years = [];
            $this->focusYear = (string) $plan->year;
        }

        $this->refreshMatrix();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('annual_plans.annual_plan_created') : __('annual_plans.annual_plan_updated'),
        );
    }

    public function confirmDelete(int $planId): void
    {
        Gate::authorize('delete', AnnualPlan::query()->findOrFail($planId));

        $this->deletingPlanId = $planId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingPlanId = null;
        $this->showDeleteModal = false;
    }

    /**
     * Delete the plan and everything committed inside it.
     *
     * AnnualPlan::delete() brackets the observer's removal of the plan's
     * targets in a transaction, so a failure partway through cannot leave the
     * targets gone and the plan standing.
     */
    public function deletePlan(): void
    {
        if ($this->deletingPlanId === null) {
            return;
        }

        $plan = AnnualPlan::query()->findOrFail($this->deletingPlanId);

        Gate::authorize('delete', $plan);

        $plan->delete();

        $this->deletingPlanId = null;
        $this->showDeleteModal = false;
        $this->refreshMatrix();

        Flux::toast(variant: 'success', text: __('annual_plans.annual_plan_deleted'));
    }

    public function closePlanModal(): void
    {
        $this->showPlanModal = false;
        $this->resetPlanForm();
    }

    /**
     * Drop every memoised read of the grid after a write.
     *
     * The cells, the column totals and the year list are all computed
     * properties resolved earlier in the same request, so a write that is not
     * followed by this leaves the matrix rendering the state it had before.
     *
     * The page is deliberately left alone. Rows are programs, and a write
     * touches a cell rather than the row set, so resetting to page one would
     * throw a reader editing the twentieth program back to the first for no
     * gain.
     */
    protected function refreshMatrix(): void
    {
        unset(
            $this->yearOptions,
            $this->yearRange,
            $this->yearColumns,
            $this->activeYear,
            $this->managerOptions,
            $this->programRows,
            $this->planCells,
            $this->planDelivery,
            $this->yearStats,
            $this->yearRail,
            $this->cockpit,
            $this->yearFormOptions,
            $this->deletingPlan,
        );
    }

    protected function resetPlanForm(): void
    {
        $this->resetValidation();
        $this->editingPlanId = null;
        $this->planProgramId = '';
        $this->planManagerId = '';
        $this->planYear = '';
    }
}
