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
 * row; targets (`plan_indicators`), reports and schedules are not. A copied indicator's `target` stays NULL: its default target is
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

            if ($lockedProposal->isPayroll()) {
                $this->refuse('proposal', 'results_framework.payroll_has_no_framework');
            }

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
     * Copy only what was ticked in the proposal page's program-framework
     * picker: the chosen results (parents before children) and the chosen
     * indicators, merging with what the proposal already has. A result or
     * indicator already here (same level and wording, trimmed and
     * case-insensitive, or copied from that row) is never duplicated; with
     * `$fillTargets`, an indicator already here whose target is empty takes
     * the program's target, and a new one is copied with it.
     *
     * @param  list<int>  $resultIds  program-framework result ids to copy
     * @param  list<int>  $indicatorIds  program-framework indicator ids to copy
     * @return array{results: int, indicators: int, targets: int}
     *
     * @throws ValidationException
     */
    public function copySelection(Proposal $proposal, array $resultIds, array $indicatorIds, bool $fillTargets): array
    {
        Gate::authorize('results.create');
        Gate::authorize('view', $proposal);

        return DB::transaction(function () use ($proposal, $resultIds, $indicatorIds, $fillTargets): array {
            $lockedProposal = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedProposal->isPayroll()) {
                $this->refuse('proposal', 'results_framework.payroll_has_no_framework');
            }

            if (ProposalFramework::isStructureLocked($lockedProposal) && ! Gate::allows('results.update')) {
                $this->refuse('structure', 'results_framework.structural_lock');
            }

            $programIds = ProposalFramework::programIds($lockedProposal);
            $programId = ProposalFramework::sharedProgramId($lockedProposal, $programIds);
            $now = now();

            /** @var Collection<int, Result> $source */
            $source = Result::query()
                ->programFramework()
                ->whereIn('program_id', $programIds)
                ->where('type', '!=', ResultType::Activity->value)
                ->orderBy('id')
                ->get(['id', 'parent_id', 'statement', 'type', 'activity_kind', 'checkout_period']);

            $sourceIds = $source->modelKeys();
            $picked = array_flip(array_intersect(array_map('intval', $resultIds), $sourceIds));

            $existing = Result::query()
                ->where('proposal_id', $lockedProposal->id)
                ->get(['id', 'type', 'statement', 'copied_from_id']);

            $mapped = [];

            foreach ($source as $row) {
                $match = $existing->first(fn (Result $here): bool => $here->copied_from_id === $row->id
                    || ($here->type === $row->type && self::sameWords($here->statement, $row->statement)));

                if ($match !== null) {
                    $mapped[$row->id] = $match->id;
                }
            }

            $counts = ['results' => 0, 'indicators' => 0, 'targets' => 0];
            $childrenOf = $source->groupBy(fn (Result $row): int => (int) $row->parent_id);
            $level = $source->filter(fn (Result $row): bool => $row->parent_id === null || ! in_array($row->parent_id, $sourceIds, true))->values();

            while ($level->isNotEmpty()) {
                $new = $level->filter(fn (Result $row): bool => ! isset($mapped[$row->id])
                    && isset($picked[$row->id])
                    && ($row->parent_id === null || ! in_array($row->parent_id, $sourceIds, true) || isset($mapped[$row->parent_id])));

                if ($new->isNotEmpty()) {
                    Result::query()->insert($new->map(fn (Result $row): array => [
                        'statement' => $row->statement,
                        'type' => $row->type->value,
                        'activity_kind' => $row->activity_kind?->value,
                        'checkout_period' => $row->checkout_period->value,
                        'program_id' => $programId,
                        'proposal_id' => $lockedProposal->id,
                        'parent_id' => $row->parent_id === null ? null : ($mapped[$row->parent_id] ?? null),
                        'contributes_to_result_id' => $row->parent_id === null && $row->type !== ResultType::CrossCutting ? $row->id : null,
                        'copied_from_id' => $row->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all());

                    $mapped += Result::query()
                        ->where('proposal_id', $lockedProposal->id)
                        ->whereIn('copied_from_id', $new->modelKeys())
                        ->pluck('id', 'copied_from_id')
                        ->map(fn (int|string $id): int => (int) $id)
                        ->all();
                    $counts['results'] += $new->count();
                }

                $level = $level->flatMap(fn (Result $row): Collection => $childrenOf->get($row->id, new Collection))->values();
            }

            $sourceIndicators = Indicator::query()
                ->whereIn('id', array_map('intval', $indicatorIds))
                ->whereIn('result_id', $sourceIds)
                ->orderBy('id')
                ->get(['id', 'name', 'result_id', 'library_indicator_id', 'code', 'measure', 'unit', 'baseline', 'target', 'frequency']);
            $hereIndicators = Indicator::query()
                ->whereIn('result_id', array_values($mapped))
                ->get(['id', 'name', 'result_id', 'target', 'copied_from_id']);

            $rows = [];

            foreach ($sourceIndicators as $indicator) {
                $resultId = $mapped[$indicator->result_id] ?? null;

                if ($resultId === null) {
                    continue;
                }

                $match = $hereIndicators->first(fn (Indicator $here): bool => $here->result_id === $resultId
                    && ($here->copied_from_id === $indicator->id || self::sameWords($here->name, $indicator->name)));

                if ($match !== null) {
                    if ($fillTargets && $match->target === null && $indicator->target !== null) {
                        $match->forceFill(['target' => $indicator->target])->save();
                        $counts['targets']++;
                    }

                    continue;
                }

                $rows[] = [
                    'name' => $indicator->name,
                    'result_id' => $resultId,
                    'library_indicator_id' => $indicator->library_indicator_id,
                    'copied_from_id' => $indicator->id,
                    'code' => $indicator->code,
                    'project_id' => null,
                    'measure' => $indicator->measure->value,
                    'unit' => $indicator->unit,
                    'baseline' => $indicator->baseline,
                    'target' => $fillTargets ? $indicator->target : null,
                    'frequency' => $indicator->frequency->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $counts['indicators']++;

                if ($fillTargets && $indicator->target !== null) {
                    $counts['targets']++;
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Indicator::query()->insert($chunk);
            }

            if (array_sum($counts) === 0) {
                $this->refuse('picker', 'proposals.picker.nothing_ticked');
            }

            $lockedProposal->recordActivity('proposal.framework_copied', [], $counts);

            return $counts;
        });
    }

    /**
     * Whether two statements say the same thing: trimmed, spaces collapsed,
     * case-insensitive.
     */
    public static function sameWords(?string $a, ?string $b): bool
    {
        $normal = fn (?string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));

        return $normal($a) === $normal($b);
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
