<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Result;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The result form, its delete confirmation and the cache reset every write
 * ends with.
 *
 * Used by results/index only. forgetFramework() unsets the computed reads of
 * BuildsResultsFramework, BuildsResultsTree and SummarisesResults, so a write
 * re-renders the tree it just changed in the same request.
 *
 * @property-read Collection<int, Result> $parentOptions
 */
trait ManagesResultForm
{
    public bool $showResultModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingResultId = null;

    #[Locked]
    public ?int $deletingResultId = null;

    public string $statement = '';

    public string $resultType = 'outcome';

    public string $checkoutPeriod = 'quarterly';

    public string $resultProgramId = '';

    public string $parentId = '';

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * The level a statement of this type contributes to, or null for an impact,
     * which sits at the top of the chain.
     */
    protected function parentLevelOf(string $type): ?ResultType
    {
        return match (ResultType::tryFrom($type)) {
            ResultType::Output => ResultType::Outcome,
            ResultType::Outcome => ResultType::Impact,
            default => null,
        };
    }

    /**
     * Results this one may contribute to: only statements one level up the
     * chain — an output under an outcome, an outcome under an impact.
     *
     * Excludes the node and its descendants so ResultObserver's
     * InvalidParentException stays a backstop. Unlike milestones there is no
     * project constraint.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $parentLevel = $this->parentLevelOf($this->resultType);

        if ($parentLevel === null) {
            return new Collection;
        }

        $excludedIds = [];

        if ($this->editingResultId !== null) {
            $result = Result::query()
                ->whereKey($this->editingResultId)
                ->with('childrenRecursive')
                ->first();

            if ($result !== null) {
                $excludedIds = $result->descendants()->pluck('id')->push($result->id)->all();
            }
        }

        return Result::query()
            ->select(['id', 'statement', 'type', 'program_id'])
            ->with('program:id')
            ->where('type', $parentLevel->value)
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('program_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * A parent chosen for the old level cannot hold the new one, so a change
     * of type drops it rather than carrying an illegal link to the save.
     */
    public function updatedResultType(): void
    {
        unset($this->parentOptions);

        if ($this->parentId !== '' && ! $this->parentOptions->contains('id', (int) $this->parentId)) {
            $this->parentId = '';
        }
    }

    /**
     * A contributing statement lives in its parent's program, so choosing a
     * parent sets the program to match.
     */
    public function updatedParentId(): void
    {
        if ($this->parentId === '') {
            return;
        }

        $parent = Result::query()->select(['id', 'program_id'])->find((int) $this->parentId);

        if ($parent !== null) {
            $this->resultProgramId = (string) ($parent->program_id ?? '');
        }
    }

    /**
     * Whether this result may be deleted.
     *
     * Refused when it has children (parent_id cascades, taking the subtree) or
     * indicators (indicators cascade, and plan_indicators and reports cascade
     * from those — three tables lost behind one click).
     */
    protected function canDelete(int $resultId): bool
    {
        $result = Result::query()
            ->select(['id'])
            ->withCount(['children', 'indicators'])
            ->find($resultId);

        return $result !== null && $result->children_count === 0 && $result->indicators_count === 0;
    }

    /**
     * Open the create form, optionally pre-wired under an existing statement.
     */
    public function openCreateModal(?int $parentResultId = null): void
    {
        Gate::authorize('results.create');

        $this->resetResultForm();

        if ($parentResultId !== null) {
            $parent = Result::query()
                ->select(['id', 'program_id', 'type'])
                ->find($parentResultId);

            // An output is the bottom of the chain: nothing can contribute to it.
            if ($parent !== null && $parent->type !== ResultType::Output) {
                $this->parentId = (string) $parent->id;
                $this->resultProgramId = (string) ($parent->program_id ?? '');
                $this->resultType = $parent->type === ResultType::Impact
                    ? ResultType::Outcome->value
                    : ResultType::Output->value;
            }
        }

        $this->showResultModal = true;
    }

    public function openEditModal(int $resultId): void
    {
        Gate::authorize('results.update');

        $result = Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->findOrFail($resultId);

        $this->resetValidation();
        $this->editingResultId = $result->id;
        $this->statement = $result->statement;
        $this->resultType = $result->type->value;
        $this->checkoutPeriod = $result->checkout_period->value;
        $this->resultProgramId = (string) ($result->program_id ?? '');
        $this->parentId = (string) ($result->parent_id ?? '');
        $this->showResultModal = true;
    }

    public function saveResult(): void
    {
        Gate::authorize($this->editingResultId === null ? 'results.create' : 'results.update');

        $this->statement = trim($this->statement);

        $validated = $this->validate([
            'statement' => ['required', 'string'],
            'resultType' => ['required', new Enum(ResultType::class)],
            'checkoutPeriod' => ['required', new Enum(Period::class)],
            'resultProgramId' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'parentId' => ['nullable', 'integer', Rule::exists(Result::class, 'id')],
        ], [
            'statement.required' => __('results_framework.write_the_result_statement'),
            'resultType.required' => __('results_framework.choose_impact_outcome_or_output'),
            'checkoutPeriod.required' => __('common.choose_how_often_this_is_checked'),
        ]);

        $result = $this->editingResultId === null
            ? new Result
            : Result::query()->findOrFail($this->editingResultId);

        $parentId = $validated['parentId'] === '' || $validated['parentId'] === null ? null : (int) $validated['parentId'];
        $programId = $validated['resultProgramId'] === '' || $validated['resultProgramId'] === null ? null : (int) $validated['resultProgramId'];
        $type = ResultType::from($validated['resultType']);

        if ($parentId !== null) {
            $parent = Result::query()->select(['id', 'type', 'program_id'])->findOrFail($parentId);

            if ($parent->type !== $this->parentLevelOf($type->value)) {
                $this->addError('parentId', __('results_framework.a_result_contributes_only_to_the_level_directly_above'));

                return;
            }

            // A contributing statement always lives in its parent's program.
            $programId = $parent->program_id;
        }

        $subtreeIds = [];

        if ($result->exists) {
            $children = Result::query()->select(['id', 'type'])->where('parent_id', $result->id)->get();

            if ($children->isNotEmpty() && $type !== $result->type
                && $children->contains(fn (Result $child): bool => $this->parentLevelOf($child->type->value) !== $type)) {
                $this->addError('resultType', __('results_framework.nested_results_still_sit_at_the_level_below'));

                return;
            }

            if ($programId !== $result->program_id) {
                $subtreeIds = $result->descendants()->pluck('id')->push($result->id)->all();

                if ($programId !== null && $this->programChangeBreaksChain($subtreeIds, $programId)) {
                    $this->addError('resultProgramId', __('results_framework.moving_this_result_would_break_its_indicators_or_targets'));

                    return;
                }
            }
        }

        DB::transaction(function () use ($result, $validated, $type, $programId, $parentId, $subtreeIds): void {
            $result->fill([
                'statement' => $validated['statement'],
                'type' => $type,
                'checkout_period' => $validated['checkoutPeriod'],
                'program_id' => $programId,
                'parent_id' => $parentId,
            ])->save();

            // The statements below move with it, so the tree never straddles programs.
            if ($subtreeIds !== []) {
                Result::query()->whereIn('id', $subtreeIds)->whereKeyNot($result->id)->update(['program_id' => $programId]);
            }
        });

        $wasRecentlyCreated = $result->wasRecentlyCreated;

        $this->showResultModal = false;
        $this->resetResultForm();
        $this->forgetFramework();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('results_framework.result_added') : __('results_framework.result_updated'),
        );
    }

    /**
     * Whether moving these statements into a program would strand what hangs
     * off them: an indicator scoped to a project in another program, or a
     * target committed in another program's annual plan.
     *
     * @param  array<int>  $resultIds
     */
    protected function programChangeBreaksChain(array $resultIds, int $programId): bool
    {
        $indicatorIds = Indicator::query()->whereIn('result_id', $resultIds)->select('id');

        $strandedIndicator = Indicator::query()
            ->whereIn('result_id', $resultIds)
            ->whereHas('project', fn ($query) => $query->where('program_id', '!=', $programId))
            ->exists();

        $strandedTarget = PlanIndicator::query()
            ->whereIn('indicator_id', $indicatorIds)
            ->where('source_type', 'annual_plan')
            ->whereIn('source_id', AnnualPlan::query()->select('id')->where('program_id', '!=', $programId))
            ->exists();

        return $strandedIndicator || $strandedTarget;
    }

    public function confirmDelete(int $resultId): void
    {
        Gate::authorize('results.delete');

        $this->deletingResultId = $resultId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingResultId = null;
        $this->showDeleteModal = false;
    }

    public function deleteResult(): void
    {
        Gate::authorize('results.delete');

        if ($this->deletingResultId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingResultId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('results_framework.this_result_still_has_nested_results_or_indicators_remove_th'),
            );

            return;
        }

        Result::query()->findOrFail($this->deletingResultId)->delete();

        $this->cancelDelete();
        $this->forgetFramework();

        Flux::toast(variant: 'success', text: __('results_framework.result_deleted'));
    }

    public function closeResultModal(): void
    {
        $this->showResultModal = false;
        $this->resetResultForm();
    }

    /**
     * Drop every cached read of the framework after a write, so the same
     * request re-renders the tree it just changed rather than the old one.
     */
    protected function forgetFramework(): void
    {
        foreach (['allResults', 'resultsById', 'childCounts', 'measurements', 'groupedRoots', 'visibleNodes', 'overview', 'focusCounts', 'parentOptions'] as $property) {
            unset($this->{$property});
        }
    }

    protected function resetResultForm(): void
    {
        $this->resetValidation();
        $this->editingResultId = null;
        $this->statement = '';
        $this->resultType = 'outcome';
        $this->checkoutPeriod = 'quarterly';
        $this->resultProgramId = '';
        $this->parentId = '';
    }
}
