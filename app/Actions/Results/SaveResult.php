<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit one logframe result, in the program framework or in a
 * proposal's own framework (contracts §11.2). The one place the chain rules
 * become messages; ResultObserver is the backstop under it.
 *
 * - Scope: a parent must sit in the same framework; an existing result is
 *   reloaded through the framework it is edited from (another proposal's node
 *   is not found).
 * - Levels: activity → output → outcome → impact, nothing under an activity;
 *   cross-cutting has no parent and no children.
 * - Program: a child takes its parent's; a proposal root takes the program
 *   its projects share (else NULL, multi-program); a program-framework root
 *   takes the one chosen. A changed program cascades to the subtree unless it
 *   would strand an indicator or an annual-plan target.
 * - Contributes-to: proposal roots only, to a program-framework result of one
 *   of the proposal's programs.
 * - Lock: once the proposal reaches `submitted`, creating, moving or
 *   retyping takes `results.update`.
 *
 * Error keys: statement, type, checkout_period, program_id, parent_id,
 * contributes_to_result_id, structure.
 */
final class SaveResult
{
    /**
     * @param  array{statement?: mixed, type?: mixed, checkout_period?: mixed, program_id?: mixed, parent_id?: mixed, contributes_to_result_id?: mixed}  $validated
     *
     * @throws ValidationException
     */
    public function handle(?Result $existing, array $validated, ?Proposal $proposal = null): Result
    {
        Gate::authorize($existing === null ? 'results.create' : 'results.update');

        $input = $this->validateInput($validated);

        return DB::transaction(function () use ($existing, $input, $proposal): Result {
            $lockedProposal = $proposal === null
                ? null
                : Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            $proposalId = $lockedProposal?->id;

            $result = $existing === null
                ? new Result(['proposal_id' => $proposalId])
                : $this->inFramework(Result::query(), $proposalId)->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();

            $type = $input['type'];
            $parentId = $input['parent_id'];
            $isStructural = ! $result->exists || $result->parent_id !== $parentId || $result->type !== $type;

            if ($lockedProposal !== null && $isStructural
                && ProposalFramework::isStructureLocked($lockedProposal) && ! Gate::allows('results.update')) {
                $this->refuse('structure', 'results_framework.structural_lock');
            }

            $parent = $parentId === null ? null : $this->lockParent($parentId, $proposalId);

            $this->ensureLevelFits($type, $parent);

            if ($result->exists && $type !== $result->type) {
                $this->ensureChildrenStillFit($result, $type);
            }

            $programIds = $lockedProposal === null ? [] : ProposalFramework::programIds($lockedProposal);
            $programId = match (true) {
                $parent !== null => $parent->program_id,
                $lockedProposal !== null => ProposalFramework::sharedProgramId($lockedProposal, $programIds),
                default => $input['program_id'],
            };

            $contributesTo = $input['contributes_to_result_id'];

            if ($contributesTo !== null) {
                $this->ensureContributionFits($contributesTo, $parent, $lockedProposal, $programIds);
            }

            $subtreeIds = [];

            if ($result->exists && $programId !== $result->program_id) {
                $subtreeIds = $result->descendants()->pluck('id')->push($result->id)->all();

                if ($programId !== null && $this->programChangeBreaksChain($subtreeIds, $programId)) {
                    $this->refuse('program_id', 'results_framework.moving_this_result_would_break_its_indicators_or_targets');
                }
            }

            $result->fill([
                'statement' => $input['statement'],
                'type' => $type,
                'checkout_period' => $input['checkout_period'],
                'program_id' => $programId,
                'parent_id' => $parentId,
                'contributes_to_result_id' => $contributesTo,
            ]);

            try {
                $result->save();
            } catch (QueryException $exception) {
                if ($parentId !== null && $this->isMissingParent($exception)) {
                    $this->refuse('parent_id', 'results_framework.parent_gone');
                }

                throw $exception;
            }

            // The statements below move with it, so the tree never straddles programs.
            if ($subtreeIds !== []) {
                Result::query()->whereIn('id', $subtreeIds)->whereKeyNot($result->id)->update(['program_id' => $programId]);
            }

            return $result;
        });
    }

    /**
     * Shape and type-check the scalar input. Ids are checked against the
     * framework later, under locks.
     *
     * @param  array<string, mixed>  $validated
     * @return array{statement: string, type: ResultType, checkout_period: string, program_id: int|null, parent_id: int|null, contributes_to_result_id: int|null}
     *
     * @throws ValidationException
     */
    private function validateInput(array $validated): array
    {
        $validated['statement'] = trim((string) ($validated['statement'] ?? ''));

        foreach (['program_id', 'parent_id', 'contributes_to_result_id'] as $key) {
            if (($validated[$key] ?? null) === '') {
                $validated[$key] = null;
            }
        }

        $data = Validator::make($validated, [
            'statement' => ['required', 'string'],
            'type' => ['required', Rule::enum(ResultType::class)],
            'checkout_period' => ['required', Rule::enum(Period::class)],
            'program_id' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'parent_id' => ['nullable', 'integer'],
            'contributes_to_result_id' => ['nullable', 'integer'],
        ], [
            'statement.required' => __('results_framework.write_the_result_statement'),
            'type.required' => __('results_framework.choose_impact_outcome_or_output'),
            'type.enum' => __('results_framework.choose_impact_outcome_or_output'),
            'checkout_period.required' => __('common.choose_how_often_this_is_checked'),
        ])->validate();

        return [
            'statement' => $data['statement'],
            'type' => ResultType::from($data['type']),
            'checkout_period' => (string) $data['checkout_period'],
            'program_id' => isset($data['program_id']) ? (int) $data['program_id'] : null,
            'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            'contributes_to_result_id' => isset($data['contributes_to_result_id']) ? (int) $data['contributes_to_result_id'] : null,
        ];
    }

    /**
     * Narrow a results query to one framework: the proposal's, or the
     * program framework when there is no proposal.
     *
     * @param  Builder<Result>  $query
     * @return Builder<Result>
     */
    private function inFramework(Builder $query, ?int $proposalId): Builder
    {
        return $proposalId === null
            ? $query->programFramework()
            : $query->proposalFramework($proposalId);
    }

    /**
     * Lock the chosen parent inside its framework, so it cannot be deleted
     * until this save commits.
     *
     * @throws ValidationException
     */
    private function lockParent(int $parentId, ?int $proposalId): Result
    {
        $parent = $this->inFramework(Result::query(), $proposalId)
            ->select(['id', 'type', 'program_id', 'proposal_id'])
            ->whereKey($parentId)
            ->lockForUpdate()
            ->first();

        if ($parent !== null) {
            return $parent;
        }

        $this->refuse('parent_id', Result::query()->whereKey($parentId)->exists()
            ? 'results_framework.other_framework'
            : 'results_framework.parent_gone');
    }

    /**
     * @throws ValidationException
     */
    private function ensureLevelFits(ResultType $type, ?Result $parent): void
    {
        if ($type === ResultType::CrossCutting && $parent !== null) {
            $this->refuse('parent_id', 'results_framework.cross_cutting_root');
        }

        if ($parent?->type === ResultType::CrossCutting) {
            $this->refuse('parent_id', 'results_framework.cross_cutting_root');
        }

        if ($type === ResultType::Activity && $parent?->type !== ResultType::Output) {
            $this->refuse('parent_id', 'results_framework.activity_under_output');
        }

        if ($parent !== null && $type->parentLevel() !== $parent->type) {
            $this->refuse('parent_id', 'results_framework.a_result_contributes_only_to_the_level_directly_above');
        }
    }

    /**
     * A new level must still hold the results already nested under it.
     *
     * @throws ValidationException
     */
    private function ensureChildrenStillFit(Result $result, ResultType $type): void
    {
        $children = Result::query()->select(['id', 'type'])->where('parent_id', $result->id)->get();

        if ($children->isEmpty()) {
            return;
        }

        if ($type === ResultType::CrossCutting) {
            $this->refuse('type', 'results_framework.cross_cutting_root');
        }

        if ($children->contains(fn (Result $child): bool => $child->type->parentLevel() !== $type)) {
            $this->refuse('type', 'results_framework.nested_results_still_sit_at_the_level_below');
        }
    }

    /**
     * @param  list<int>  $programIds  the proposal's programs
     *
     * @throws ValidationException
     */
    private function ensureContributionFits(int $contributesTo, ?Result $parent, ?Proposal $proposal, array $programIds): void
    {
        $fits = $proposal !== null
            && $parent === null
            && Result::query()
                ->programFramework()
                ->whereKey($contributesTo)
                ->whereIn('program_id', $programIds)
                ->exists();

        if (! $fits) {
            $this->refuse('contributes_to_result_id', 'results_framework.contributes_to');
        }
    }

    /**
     * Whether moving these statements into a program would strand what hangs
     * off them: an indicator scoped to a project in another program, or a
     * target committed in another program's annual plan.
     *
     * @param  array<int>  $resultIds
     */
    private function programChangeBreaksChain(array $resultIds, int $programId): bool
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

    /**
     * A foreign-key failure on insert/update: the parent row is gone.
     */
    private function isMissingParent(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1452;
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $field, string $messageKey): never
    {
        throw ValidationException::withMessages([$field => __($messageKey)]);
    }
}
