<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Actions\Results\DeleteResult;
use App\Actions\Results\SaveResult;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The result form, its delete confirmation and the cache reset every write
 * ends with.
 *
 * Used by results/index and the proposal Logical Framework tab, always with
 * BuildsResultsFramework, whose frameworkResults() every id is loaded
 * through. The form writes into one framework: `formProposalId` (null = the
 * program framework), taken from the pinned proposal, the parent a child is
 * added under, or the result being edited — never from the request.
 * forgetFramework() unsets the computed reads of BuildsResultsFramework,
 * BuildsResultsTree and SummarisesResults, so a write re-renders the tree it
 * just changed in the same request.
 *
 * @property-read Collection<int, Result> $parentOptions
 * @property-read Collection<int, Result> $contributesToOptions
 *
 * @method ?int fixedProposalId()
 * @method Builder<Result> frameworkResults()
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
        'contributes_to_result_id' => 'contributesToId',
        'structure' => 'statement',
    ];

    public bool $showResultModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingResultId = null;

    #[Locked]
    public ?int $deletingResultId = null;

    /** The proposal whose framework the form writes into; null = the program framework. */
    #[Locked]
    public ?int $formProposalId = null;

    public string $statement = '';

    public string $resultType = 'outcome';

    public string $checkoutPeriod = 'quarterly';

    public string $resultProgramId = '';

    public string $parentId = '';

    public string $contributesToId = '';

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
     * The level added under a statement of this level, or null when nothing
     * may sit under it (an activity, a cross-cutting result).
     */
    protected function childLevelOf(ResultType $type): ?ResultType
    {
        if (! $type->acceptsChildren()) {
            return null;
        }

        foreach (ResultType::cases() as $level) {
            if ($level->parentLevel() === $type) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Results in the framework the form writes into.
     *
     * @return Builder<Result>
     */
    protected function formFramework(): Builder
    {
        return $this->formProposalId === null
            ? Result::query()->programFramework()
            : Result::query()->proposalFramework($this->formProposalId);
    }

    /**
     * Results this one may contribute to: only statements one level up the
     * chain, in the same framework — an activity under an output, an output
     * under an outcome, an outcome under an impact.
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

        return $this->formFramework()
            ->select(['id', 'statement', 'type', 'program_id'])
            ->with('program:id')
            ->where('type', $parentLevel->value)
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('program_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the form is placing a root of a proposal framework, the only
     * statement that may contribute to the program framework (contracts §11.2).
     */
    public function placesProposalRoot(): bool
    {
        return $this->formProposalId !== null
            && $this->parentId === ''
            && $this->resultType !== ResultType::Activity->value;
    }

    /**
     * Program-framework results a proposal root may contribute to: any level,
     * in one of the programs the proposal's projects belong to.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function contributesToOptions(): Collection
    {
        if (! $this->placesProposalRoot()) {
            return new Collection;
        }

        $proposal = Proposal::query()->select(['id'])->find($this->formProposalId);

        if ($proposal === null) {
            return new Collection;
        }

        return Result::query()
            ->programFramework()
            ->select(['id', 'statement', 'type', 'program_id'])
            ->with('program:id')
            ->whereIn('program_id', ProposalFramework::programIds($proposal))
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
        unset($this->parentOptions, $this->contributesToOptions);

        if ($this->parentId !== '' && ! $this->parentOptions->contains('id', (int) $this->parentId)) {
            $this->parentId = '';
        }

        if (! $this->placesProposalRoot()) {
            $this->contributesToId = '';
        }
    }

    /**
     * A contributing statement lives in its parent's program, so choosing a
     * parent sets the program to match; a nested statement contributes to
     * the program framework only through its root.
     */
    public function updatedParentId(): void
    {
        unset($this->contributesToOptions);

        if ($this->parentId === '') {
            return;
        }

        $this->contributesToId = '';

        $parent = $this->formFramework()->select(['id', 'program_id'])->find((int) $this->parentId);

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
        $result = $this->frameworkResults()
            ->select(['id'])
            ->withCount(['children', 'indicators'])
            ->find($resultId);

        return $result !== null && $result->children_count === 0 && $result->indicators_count === 0;
    }

    /**
     * Open the create form, optionally pre-wired under an existing statement
     * in that statement's framework.
     */
    public function openCreateModal(?int $parentResultId = null): void
    {
        Gate::authorize('results.create');

        $this->resetResultForm();

        if ($parentResultId === null) {
            $this->showResultModal = true;

            return;
        }

        $parent = $this->frameworkResults()
            ->select(['id', 'program_id', 'proposal_id', 'type'])
            ->findOrFail($parentResultId);

        $childLevel = $this->childLevelOf($parent->type);

        // An activity or a cross-cutting result is the bottom of its chain: nothing nests under it.
        if ($childLevel !== null) {
            $this->formProposalId = $parent->proposal_id;
            $this->parentId = (string) $parent->id;
            $this->resultProgramId = (string) ($parent->program_id ?? '');
            $this->resultType = $childLevel->value;
        }

        $this->showResultModal = true;
    }

    public function openEditModal(int $resultId): void
    {
        Gate::authorize('results.update');

        $result = $this->frameworkResults()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'proposal_id', 'contributes_to_result_id', 'type', 'checkout_period'])
            ->findOrFail($resultId);

        $this->resetValidation();
        $this->editingResultId = $result->id;
        $this->formProposalId = $result->proposal_id;
        $this->statement = $result->statement;
        $this->resultType = $result->type->value;
        $this->checkoutPeriod = $result->checkout_period->value;
        $this->resultProgramId = (string) ($result->program_id ?? '');
        $this->parentId = (string) ($result->parent_id ?? '');
        $this->contributesToId = (string) ($result->contributes_to_result_id ?? '');
        $this->showResultModal = true;
    }

    public function saveResult(): void
    {
        Gate::authorize($this->editingResultId === null ? 'results.create' : 'results.update');

        $existing = $this->editingResultId === null ? null : $this->frameworkResults()->findOrFail($this->editingResultId);
        $proposal = $this->formProposalId === null ? null : Proposal::query()->findOrFail($this->formProposalId);

        try {
            $result = app(SaveResult::class)->handle($existing, [
                'statement' => $this->statement,
                'type' => $this->resultType,
                'checkout_period' => $this->checkoutPeriod,
                'program_id' => $this->resultProgramId,
                'parent_id' => $this->parentId,
                'contributes_to_result_id' => $this->placesProposalRoot() ? $this->contributesToId : '',
            ], $proposal);
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

        $this->deletingResultId = $this->frameworkResults()->select(['id'])->findOrFail($resultId)->id;
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

        $result = $this->frameworkResults()->with('proposal:id')->find($this->deletingResultId);

        try {
            if ($result !== null) {
                app(DeleteResult::class)->handle($result, $result->proposal);
            }
        } catch (ValidationException $exception) {
            $this->cancelDelete();

            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());

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
     * A page without one of these reads (the Logical Framework tab has no
     * overview) simply skips it.
     */
    protected function forgetFramework(): void
    {
        foreach (['allResults', 'resultsById', 'childCounts', 'measurements', 'groupedRoots', 'frameworkRoots', 'visibleNodes', 'overview', 'focusCounts', 'parentOptions', 'contributesToOptions'] as $property) {
            if (method_exists($this, $property)) {
                unset($this->{$property});
            }
        }
    }

    protected function resetResultForm(): void
    {
        $this->resetValidation();
        $this->editingResultId = null;
        $this->formProposalId = $this->fixedProposalId();
        $this->statement = '';
        $this->resultType = 'outcome';
        $this->checkoutPeriod = 'quarterly';
        $this->resultProgramId = '';
        $this->parentId = '';
        $this->contributesToId = '';
    }
}
