<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Copy a program-framework root and its whole subtree into a proposal's own
 * framework (contracts §11.2, T32): "Start from Program Framework", and T10's
 * renewal copy, whose source is a root of the proposal's previous proposal.
 * There is no second copy routine.
 *
 * Results and their indicators are copied with `copied_from_id` set on every
 * row; targets (`plan_indicators`), reports, schedules and milestone links
 * are not. A copied indicator's `target` stays NULL: its default target is
 * the source's `indicators.target`, read through `copied_from_id`.
 *
 * The rows are written with batched inserts, one level at a time, so the
 * query count follows the depth of the tree, never its size. The inserts
 * skip the model events: the copy keeps the source's levels inside one
 * framework, so it is valid by construction. One `result.created` activity
 * is recorded on the new root.
 */
final class CopyResultTree
{
    /**
     * @throws ValidationException when the root is neither a program-framework root
     *                             of the proposal's programs nor a root of its
     *                             previous proposal, was already imported, or
     *                             the framework is locked for this user
     */
    public function handle(Result $sourceRoot, Proposal $proposal): Result
    {
        Gate::authorize('results.create');
        Gate::authorize('view', $proposal);

        $root = DB::transaction(function () use ($sourceRoot, $proposal): Result {
            $lockedProposal = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if (ProposalFramework::isStructureLocked($lockedProposal) && ! Gate::allows('results.update')) {
                $this->refuse('structure', 'results_framework.structural_lock');
            }

            $programIds = ProposalFramework::programIds($lockedProposal);

            $source = Result::query()
                ->whereNull('parent_id')
                ->where('type', '!=', ResultType::Activity->value)
                ->where(fn ($sources) => $sources
                    ->where(fn ($programRoots) => $programRoots->programFramework()->whereIn('program_id', $programIds))
                    ->when($lockedProposal->previous_proposal_id !== null, fn ($renewalRoots) => $renewalRoots
                        ->orWhere('proposal_id', $lockedProposal->previous_proposal_id)))
                ->whereKey($sourceRoot->getKey())
                ->first();

            if ($source === null) {
                $this->refuse('source', 'results_framework.choose_a_framework_of_this_proposal');
            }

            if ($lockedProposal->results()->where('copied_from_id', $source->id)->exists()) {
                $this->refuse('source', 'results_framework.framework_already_imported');
            }

            return $this->copy($source, $lockedProposal, ProposalFramework::sharedProgramId($lockedProposal, $programIds));
        });

        $root->recordActivity('created');

        return $root;
    }

    /**
     * The program-framework result the copied root contributes to: the source
     * itself for a program root, or the source's own link for a renewal root.
     */
    private function contributesTo(Result $source): ?int
    {
        if ($source->proposal_id !== null) {
            return $source->contributes_to_result_id;
        }

        return $source->type === ResultType::CrossCutting ? null : $source->id;
    }

    /**
     * Insert the subtree level by level (parents before children), mapping
     * each source id to its copy, then every indicator in one insert.
     */
    private function copy(Result $source, Proposal $proposal, ?int $programId): Result
    {
        $now = now();
        $copyIdOf = [];
        $level = new Collection([$source]);

        while ($level->isNotEmpty()) {
            Result::query()->insert($level->map(fn (Result $result): array => [
                'statement' => $result->statement,
                'type' => $result->type->value,
                'activity_kind' => $result->activity_kind?->value,
                'checkout_period' => $result->checkout_period->value,
                'program_id' => $programId,
                'proposal_id' => $proposal->id,
                'parent_id' => $result->parent_id === null || $result->is($source) ? null : $copyIdOf[$result->parent_id],
                'contributes_to_result_id' => $result->is($source) ? $this->contributesTo($source) : null,
                'copied_from_id' => $result->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            $copyIdOf += $proposal->results()
                ->whereIn('copied_from_id', $level->modelKeys())
                ->pluck('id', 'copied_from_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            $level = $this->childrenOf($level, $source->proposal_id);
        }

        $this->copyIndicators(array_keys($copyIdOf), $copyIdOf, $now);

        return $proposal->results()->findOrFail($copyIdOf[$source->id]);
    }

    /**
     * The next level down of the source tree, one query.
     *
     * @param  Collection<int, Result>  $level
     * @return Collection<int, Result>
     */
    private function childrenOf(Collection $level, ?int $sourceProposalId): Collection
    {
        return Result::query()
            ->where('proposal_id', $sourceProposalId)
            ->whereIn('parent_id', $level->modelKeys())
            ->orderBy('id')
            ->get(['id', 'parent_id', 'statement', 'type', 'activity_kind', 'checkout_period']);
    }

    /**
     * Copy the indicators of every copied result, without targets.
     *
     * @param  list<int>  $sourceResultIds
     * @param  array<int, int>  $copyIdOf  source result id => copied result id
     */
    private function copyIndicators(array $sourceResultIds, array $copyIdOf, CarbonInterface $now): void
    {
        $rows = Indicator::query()
            ->whereIn('result_id', $sourceResultIds)
            ->orderBy('id')
            ->get(['id', 'name', 'result_id', 'library_indicator_id', 'code', 'measure', 'unit', 'baseline', 'frequency'])
            ->map(fn (Indicator $indicator): array => [
                'name' => $indicator->name,
                'result_id' => $copyIdOf[$indicator->result_id],
                'library_indicator_id' => $indicator->library_indicator_id,
                'copied_from_id' => $indicator->id,
                'code' => $indicator->code,
                'project_id' => null,
                'measure' => $indicator->measure->value,
                'unit' => $indicator->unit,
                'baseline' => $indicator->baseline,
                'target' => null,
                'frequency' => $indicator->frequency->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($rows->chunk(500) as $chunk) {
            Indicator::query()->insert($chunk->values()->all());
        }
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $field, string $messageKey): never
    {
        throw ValidationException::withMessages([$field => __($messageKey)]);
    }
}
