<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Actions\Results\DeleteResult;
use App\Actions\Results\SaveResult;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Result;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
    /**
     * SaveResult's error keys => the form property each message belongs to.
     *
     * @var array<string, string>
     */
    private const array FORM_FIELDS = [
        'statement' => 'statement',
        'type' => 'resultType',
        'checkout_period' => 'checkoutPeriod',
        'program_id' => 'resultProgramId',
        'parent_id' => 'parentId',
    ];

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
     * The level a statement of this type contributes to, or null for a level
     * with no parent (impact, cross-cutting, or an unknown value).
     */
    protected function parentLevelOf(string $type): ?ResultType
    {
        return ResultType::tryFrom($type)?->parentLevel();
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
            ->programFramework()
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

        $existing = $this->editingResultId === null ? null : Result::query()->findOrFail($this->editingResultId);

        try {
            $result = app(SaveResult::class)->handle($existing, [
                'statement' => $this->statement,
                'type' => $this->resultType,
                'checkout_period' => $this->checkoutPeriod,
                'program_id' => $this->resultProgramId,
                'parent_id' => $this->parentId,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [
                    (self::FORM_FIELDS[$field] ?? 'statement') => $messages,
                ])->all(),
            );
        }

        $wasRecentlyCreated = $result->wasRecentlyCreated;

        $this->showResultModal = false;
        $this->resetResultForm();
        $this->forgetFramework();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('results_framework.result_added') : __('results_framework.result_updated'),
        );
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

        $result = Result::query()->find($this->deletingResultId);

        try {
            if ($result !== null) {
                app(DeleteResult::class)->handle($result);
            }
        } catch (ValidationException) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('results_framework.this_result_still_has_nested_results_or_indicators_remove_th'),
            );

            return;
        }

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
