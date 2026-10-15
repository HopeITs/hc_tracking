<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Results\CopyResultTree;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Result;
use App\Support\ProposalFramework;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * "Start from Program Framework" on the proposal Logical Framework tab (T32):
 * pick a program-framework root (track) of the proposal's programs, see how
 * many results and indicators it carries, confirm, and CopyResultTree does
 * the copy. The picker offers only roots not yet copied into this proposal;
 * the action re-checks every id it receives.
 *
 * @property-read Collection<int, array{id: int, statement: string, program_id: ?int, type: ResultType, results: int, indicators: int}> $copySources
 */
trait CopiesProgramFramework
{
    public bool $showCopyModal = false;

    public bool $showCopyConfirmModal = false;

    public int|string|null $copySourceId = null;

    /**
     * The roots that may be copied, each with the size of its subtree, from
     * three queries: the proposal's programs, the program-framework results
     * of those programs, and their indicator counts.
     *
     * @return Collection<int, array{id: int, statement: string, program_id: ?int, type: ResultType, results: int, indicators: int}>
     */
    #[Computed]
    public function copySources(): Collection
    {
        $programIds = ProposalFramework::programIds($this->proposal);

        if ($programIds === []) {
            return collect();
        }

        $results = Result::query()
            ->programFramework()
            ->whereIn('program_id', $programIds)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'program_id', 'statement', 'type']);

        $indicatorCounts = Indicator::query()
            ->whereIn('result_id', $results->modelKeys())
            ->selectRaw('result_id, count(*) as aggregate')
            ->groupBy('result_id')
            ->pluck('aggregate', 'result_id');

        $alreadyCopied = $this->allResults->pluck('copied_from_id')->filter()->flip();
        $childrenOf = $results->groupBy('parent_id');

        return $results
            ->filter(fn (Result $result): bool => $result->parent_id === null
                && $result->type !== ResultType::Activity
                && ! $alreadyCopied->has($result->id))
            ->map(function (Result $root) use ($childrenOf, $indicatorCounts): array {
                $ids = [];
                $pending = [$root->id];

                while ($pending !== []) {
                    $id = array_shift($pending);
                    $ids[] = $id;

                    foreach ($childrenOf->get($id, collect()) as $child) {
                        $pending[] = $child->id;
                    }
                }

                return [
                    'id' => $root->id,
                    'statement' => $root->statement,
                    'program_id' => $root->program_id,
                    'type' => $root->type,
                    'results' => count($ids),
                    'indicators' => (int) collect($ids)->sum(fn (int $id): int => (int) ($indicatorCounts[$id] ?? 0)),
                ];
            })
            ->values();
    }

    /**
     * The chosen root's preview, or null while none is chosen.
     *
     * @return array{id: int, statement: string, program_id: ?int, type: ResultType, results: int, indicators: int}|null
     */
    public function copyPreview(): ?array
    {
        if ($this->copySourceId === null || $this->copySourceId === '') {
            return null;
        }

        return $this->copySources->firstWhere('id', (int) $this->copySourceId);
    }

    public function openCopyModal(): void
    {
        Gate::authorize('results.create');

        $this->resetValidation('copySourceId');
        $this->copySourceId = $this->copySources->count() === 1 ? $this->copySources->first()['id'] : null;
        $this->showCopyModal = true;
    }

    public function askCopyConfirmation(): void
    {
        if ($this->copySourceId === null || $this->copySourceId === '') {
            throw ValidationException::withMessages(['copySourceId' => __('results_framework.copy_framework_choose')]);
        }

        $this->showCopyModal = false;
        $this->showCopyConfirmModal = true;
    }

    public function copyFramework(): void
    {
        $this->showCopyConfirmModal = false;

        $source = $this->copySourceId === null || $this->copySourceId === '' ? null : Result::query()->find((int) $this->copySourceId);

        if ($source === null) {
            throw ValidationException::withMessages(['copySourceId' => __('results_framework.choose_a_framework_of_this_proposal')]);
        }

        try {
            app(CopyResultTree::class)->handle($source, $this->proposal);
        } catch (ValidationException $exception) {
            $this->showCopyModal = true;

            throw ValidationException::withMessages(['copySourceId' => collect($exception->errors())->flatten()->all()]);
        }

        $this->closeCopyModal();
        $this->forgetFramework();
        unset($this->copySources);

        Flux::toast(variant: 'success', text: __('results_framework.framework_copied'));
    }

    /**
     * The confirmation's own Cancel: the choice stays, so a failed copy can
     * reopen the picker with it.
     */
    public function cancelCopyConfirmation(): void
    {
        $this->showCopyConfirmModal = false;
    }

    public function closeCopyModal(): void
    {
        $this->showCopyModal = false;
        $this->showCopyConfirmModal = false;
        $this->copySourceId = null;
    }
}
