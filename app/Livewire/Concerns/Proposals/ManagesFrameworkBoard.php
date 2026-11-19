<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Results\CopyResultTree;
use App\Actions\Results\SaveActivity;
use App\Actions\Results\SaveActivityTiming;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The Activity board and the program-framework picker of the proposal
 * page's logical framework box (components/proposals/⚡logframe).
 *
 * Every write goes through SaveActivity, SaveActivityTiming or
 * CopyResultTree, which authorise and validate again. The page provides
 * proposal(), framework(), forgetBox() and the result form's
 * confirmDelete()/openCreateModal().
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read array{results: list<array<string, mixed>>, programs: int} $pickerTree  #[Computed] pickerTree()
 */
trait ManagesFrameworkBoard
{
    public bool $boardUnfinished = false;

    /** @var list<int> */
    public array $openOutputs = [];

    #[Locked]
    public ?int $pickingId = null;

    #[Locked]
    public ?int $editingActivityId = null;

    public string $actName = '';

    public string $actMode = 'continuous';

    public string $actStart = '';

    public string $actEnd = '';

    /** @var array<int, array{name: string, target: string}> */
    public array $actCounts = [];

    public string $newCountName = '';

    public string $newCountTarget = '';

    public bool $showPicker = false;

    public function toggleOutput(int $outputId): void
    {
        $this->openOutputs = in_array($outputId, $this->openOutputs, true)
            ? array_values(array_diff($this->openOutputs, [$outputId]))
            : [...$this->openOutputs, $outputId];
    }

    /**
     * Open (or close) the inline "Which output does it deliver?" chooser.
     */
    public function pickFor(int $activityId): void
    {
        $this->pickingId = $this->pickingId === $activityId ? null : $activityId;
    }

    /**
     * Link an activity to an output; 0 unlinks it. Used by the chooser and by drag and drop.
     */
    public function linkActivity(int $activityId, int $outputId): void
    {
        $activity = $this->activityResult($activityId);

        try {
            app(SaveActivity::class)->link($this->proposal, $activity, $outputId === 0 ? null : $outputId);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->pickingId = null;

        if ($outputId !== 0 && ! in_array($outputId, $this->openOutputs, true)) {
            $this->openOutputs[] = $outputId;
        }

        $this->forgetBox();

        $output = $outputId === 0 ? null : Result::query()->find($outputId, ['id', 'statement']);
        Flux::toast(variant: 'success', text: $output === null
            ? __('proposals.board.unlinked', ['activity' => str($activity->statement)->limit(30)])
            : __('proposals.board.linked', ['activity' => str($activity->statement)->limit(30), 'output' => str($output->statement)->limit(40)]));
    }

    public function editActivity(int $activityId): void
    {
        Gate::authorize('results.update');

        if ($this->editingActivityId === $activityId) {
            $this->closeActivityEditor();

            return;
        }

        $activity = $this->activityResult($activityId);
        $row = $this->framework['activities'][$activityId] ?? null;

        $this->resetValidation();
        $this->editingActivityId = $activityId;
        $this->pickingId = null;
        $this->actName = $activity->statement;
        $this->actMode = $row['mode'] ?? 'continuous';
        $this->actStart = (string) $activity->starts_on?->toDateString();
        $this->actEnd = (string) $activity->ends_on?->toDateString();
        $this->actCounts = collect($row['indicators'] ?? [])->mapWithKeys(fn (array $indicator): array => [
            $indicator['id'] => ['name' => $indicator['name'], 'target' => $indicator['target'] === null ? '' : rtrim(rtrim(number_format($indicator['target'], 2, '.', ''), '0'), '.')],
        ])->all();
        $this->newCountName = '';
        $this->newCountTarget = '';
    }

    public function closeActivityEditor(): void
    {
        $this->editingActivityId = null;
        $this->resetValidation();
    }

    public function saveActivityName(): void
    {
        $this->writeActivity(fn (Result $activity) => app(SaveActivity::class)->rename($this->proposal, $activity, $this->actName), 'actName');
    }

    /**
     * Switching to Continuous or Event saves at once and clears the dates;
     * Dates waits until both dates are filled.
     */
    public function setActivityMode(string $mode): void
    {
        if (! in_array($mode, SaveActivityTiming::MODES, true)) {
            return;
        }

        $this->actMode = $mode;

        if ($mode !== 'dates') {
            $this->actStart = '';
            $this->actEnd = '';
            $this->saveActivityDates();
        }
    }

    public function saveActivityDates(): void
    {
        if ($this->actMode === 'dates' && ($this->actStart === '' || $this->actEnd === '')) {
            return;
        }

        $this->writeActivity(fn (Result $activity) => app(SaveActivityTiming::class)->handle($this->proposal, $activity, $this->actMode, $this->actStart ?: null, $this->actEnd ?: null), 'actEnd');
    }

    public function saveCount(int $indicatorId): void
    {
        $row = $this->actCounts[$indicatorId] ?? null;

        if ($row === null) {
            return;
        }

        $this->writeActivity(function (Result $activity) use ($indicatorId, $row) {
            $indicator = Indicator::query()->where('result_id', $activity->id)->findOrFail($indicatorId);

            return app(SaveActivity::class)->saveCount($this->proposal, $activity, $indicator, $row['name'], $row['target']);
        }, 'actCounts.'.$indicatorId.'.name');
    }

    public function addCount(): void
    {
        $saved = $this->writeActivity(fn (Result $activity) => app(SaveActivity::class)->saveCount($this->proposal, $activity, null, $this->newCountName, $this->newCountTarget), 'newCountName');

        if ($saved) {
            $this->newCountName = '';
            $this->newCountTarget = '';
            $this->actCounts = Indicator::query()
                ->where('result_id', $this->editingActivityId)
                ->orderBy('id')
                ->get(['id', 'name', 'target'])
                ->mapWithKeys(fn (Indicator $indicator): array => [
                    $indicator->id => ['name' => $indicator->name, 'target' => $indicator->target === null ? '' : rtrim(rtrim(number_format((float) $indicator->target, 2, '.', ''), '0'), '.')],
                ])->all();
        }
    }

    public function deleteCount(int $indicatorId): void
    {
        $saved = $this->writeActivity(function (Result $activity) use ($indicatorId): void {
            $indicator = Indicator::query()->where('result_id', $activity->id)->findOrFail($indicatorId);
            app(SaveActivity::class)->deleteCount($this->proposal, $activity, $indicator);
        }, 'actCounts');

        if ($saved) {
            unset($this->actCounts[$indicatorId]);
        }
    }

    /* ---------------- Program framework picker ---------------- */

    public function openPicker(): void
    {
        Gate::authorize('results.create');
        unset($this->pickerTree);
        $this->showPicker = true;
    }

    /**
     * The program framework as a tree for the picker, each result and
     * indicator marked with whether this proposal already has it.
     *
     * @return array{results: list<array<string, mixed>>, programs: int}
     */
    #[Computed]
    public function pickerTree(): array
    {
        $programIds = ProposalFramework::programIds($this->proposal);

        if ($programIds === []) {
            return ['results' => [], 'programs' => 0];
        }

        $source = Result::query()
            ->programFramework()
            ->whereIn('program_id', $programIds)
            ->where('type', '!=', ResultType::Activity->value)
            ->with(['indicators' => fn ($query) => $query->select(['id', 'result_id', 'name', 'target', 'frequency'])->orderBy('id')])
            ->orderBy('id')
            ->get(['id', 'parent_id', 'type', 'statement']);

        $here = Result::query()->where('proposal_id', $this->proposalId)->with('indicators:id,result_id,name,target,copied_from_id')->get(['id', 'type', 'statement', 'copied_from_id']);
        $ids = $source->modelKeys();
        $rows = [];

        $walk = function (Result $row, int $depth) use (&$walk, &$rows, $source, $here): void {
            $match = $here->first(fn (Result $mine): bool => $mine->copied_from_id === $row->id || ($mine->type === $row->type && CopyResultTree::sameWords($mine->statement, $row->statement)));

            $rows[] = [
                'id' => $row->id,
                'parent' => $row->parent_id,
                'depth' => $depth,
                'type' => $row->type->value,
                'typeLabel' => $row->type->label(),
                'statement' => $row->statement,
                'have' => $match !== null,
                'indicators' => $row->indicators->map(function (Indicator $indicator) use ($match): array {
                    $mine = $match?->indicators->first(fn (Indicator $have): bool => $have->copied_from_id === $indicator->id || CopyResultTree::sameWords($have->name, $indicator->name));

                    return [
                        'id' => $indicator->id,
                        'name' => $indicator->name,
                        'frequency' => $indicator->frequency->label(),
                        'target' => $indicator->target === null ? null : rtrim(rtrim(number_format((float) $indicator->target, 2, '.', ''), '0'), '.'),
                        'have' => $mine !== null,
                        'fillable' => $mine !== null && $mine->target === null && $indicator->target !== null,
                    ];
                })->values()->all(),
            ];

            foreach ($source->where('parent_id', $row->id) as $child) {
                $walk($child, $depth + 1);
            }
        };

        foreach ($source->filter(fn (Result $row): bool => $row->parent_id === null || ! in_array($row->parent_id, $ids, true)) as $root) {
            $walk($root, 0);
        }

        return ['results' => $rows, 'programs' => count($programIds)];
    }

    /**
     * Copy what was ticked in the picker.
     *
     * @param  list<int>  $resultIds
     * @param  list<int>  $indicatorIds
     */
    public function copySelection(array $resultIds, array $indicatorIds, bool $fillTargets): void
    {
        try {
            $counts = app(CopyResultTree::class)->copySelection($this->proposal, array_map('intval', $resultIds), array_map('intval', $indicatorIds), $fillTargets);
        } catch (ValidationException $exception) {
            $this->addError('picker', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->showPicker = false;
        unset($this->pickerTree);
        $this->forgetFramework();

        Flux::toast(variant: 'success', text: __('proposals.picker.copied', [
            'results' => trans_choice('proposals.picker.n_results', $counts['results'], ['count' => $counts['results']]),
            'indicators' => trans_choice('proposals.picker.n_indicators', $counts['indicators'], ['count' => $counts['indicators']]),
            'targets' => trans_choice('proposals.picker.n_targets', $counts['targets'], ['count' => $counts['targets']]),
        ]));
    }

    private function activityResult(int $activityId): Result
    {
        return Result::query()
            ->where('proposal_id', $this->proposalId)
            ->where('type', ResultType::Activity->value)
            ->findOrFail($activityId);
    }

    /**
     * Run one activity write, put a refusal under the given field, and
     * re-read the box when it worked.
     */
    private function writeActivity(callable $write, string $errorKey): bool
    {
        Gate::authorize('results.update');

        try {
            $write($this->activityResult((int) $this->editingActivityId));
        } catch (ValidationException $exception) {
            $this->addError($errorKey, (string) collect($exception->errors())->flatten()->first());

            return false;
        }

        $this->resetValidation();
        $this->forgetBox();

        return true;
    }
}
