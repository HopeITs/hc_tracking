<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save a proposal's per-center targets grid in one go (contracts §12.1,
 * D-23, D-25, D-50a).
 *
 * - Who: MEAL. Creating a cell needs `targets.create`, changing one
 *   `targets.update`, clearing one `targets.delete`; `proposals.update`
 *   alone grants nothing. Terminal proposals are read-only.
 * - Scope (BOLA): every indicator is an instance under this proposal's
 *   framework and every project one the proposal links; one foreign id
 *   rejects the whole batch.
 * - Cells: an empty value is no target, `0` a real zero target; a value
 *   needs a period; percentages are 0–100, averages uncapped. An indicator
 *   not linked to the library takes no target. A cleared cell is deleted
 *   only when no report was filed against it.
 * - Concurrency: one transaction with the proposal row locked; each cell
 *   carries the version (versionOf()) it was loaded at, null for an empty
 *   cell. Any stale cell rejects the whole batch.
 * - Writes go through Eloquent without per-row activity; one
 *   `proposal.targets_saved` summary (counts only, no values) is recorded
 *   when something changed.
 *
 * Error keys: proposal, cells.{i}, cells.{i}.value, cells.{i}.period, cells.
 */
final class SaveProposalTargetsGrid
{
    public const string SourceType = 'proposal';

    /**
     * The version of a saved cell, as the grid sends it back.
     */
    public static function versionOf(PlanIndicator $target): string
    {
        return (string) $target->updated_at?->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<array{indicator_id?: mixed, project_id?: mixed, value?: mixed, period?: mixed, target_population?: mixed, version?: mixed}>  $cells
     * @return array{created: int, updated: int, deleted: int}
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, array $cells): array
    {
        Gate::authorize('targets.update');

        $cells = $this->validateCells($cells);

        return DB::transaction(function () use ($proposal, $cells): array {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->with('projects')->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages(['proposal' => __('targets.proposal_read_only')]);
            }

            $indicators = Indicator::query()
                ->whereIn('id', array_unique(array_column($cells, 'indicator_id')))
                ->whereIn('result_id', Result::query()->proposalFramework($locked->id)->select('id'))
                ->with('result:id,program_id,proposal_id,type')
                ->get()
                ->keyBy('id');

            /** @var Collection<int, Project> $projects */
            $projects = $locked->getRelation('projects')->keyBy('id');

            $existing = PlanIndicator::query()
                ->where('source_type', self::SourceType)
                ->where('source_id', $locked->id)
                ->whereIn('indicator_id', $indicators->keys())
                ->withCount('reports')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (PlanIndicator $target): string => $target->indicator_id.':'.$target->project_id);

            $errors = [];
            $plan = [];

            foreach ($cells as $index => $cell) {
                $indicator = $indicators->get($cell['indicator_id']);
                $project = $projects->get($cell['project_id']);

                if ($indicator === null || $project === null) {
                    $errors["cells.{$index}"] = __('targets.cell_outside_proposal');

                    continue;
                }

                /** @var PlanIndicator|null $target */
                $target = $existing->get($indicator->id.':'.$project->id);

                if (($target === null ? null : self::versionOf($target)) !== $cell['version']) {
                    $errors["cells.{$index}"] = __('targets.cell_stale');

                    continue;
                }

                $refusal = $this->refusalFor($locked, $indicator, $project, $target, $cell['value']);

                if ($refusal !== null) {
                    $errors["cells.{$index}"] = $refusal;

                    continue;
                }

                $plan[] = [$cell, $indicator, $project, $target];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['cells' => __('targets.grid_refused'), ...$errors]);
            }

            return $this->write($locked, $plan);
        });
    }

    /**
     * Why this cell cannot be saved, or null.
     */
    private function refusalFor(Proposal $proposal, Indicator $indicator, Project $project, ?PlanIndicator $target, ?string $value): ?string
    {
        if ($value === null) {
            if ($target === null) {
                return null;
            }

            if ($target->reports_count > 0) {
                return __('targets.cell_has_reports');
            }

            if (Gate::denies('targets.delete')) {
                return __('targets.cell_clear_forbidden');
            }

            return null;
        }

        if ($indicator->library_indicator_id === null) {
            return __('targets.cell_indicator_not_linked');
        }

        if ($indicator->measure === IndicatorMeasure::Percentage && (float) $value > 100) {
            return __('targets.percentage_over_100');
        }

        if ($target === null) {
            $issues = PlanIndicator::chainIssuesFor($proposal, $indicator, $project);

            if ($issues !== []) {
                $issue = __($issues[0]);

                return __('targets.cell_chain_issue', ['issue' => is_string($issue) ? $issue : $issues[0]]);
            }
        }

        return null;
    }

    /**
     * Apply the checked cells and record the summary.
     *
     * @param  list<array{0: array{indicator_id: int, project_id: int, value: ?string, period: ?string, target_population: ?string, version: ?string}, 1: Indicator, 2: Project, 3: ?PlanIndicator}>  $plan
     * @return array{created: int, updated: int, deleted: int}
     */
    private function write(Proposal $proposal, array $plan): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        foreach ($plan as [$cell]) {
            $operation = match (true) {
                $cell['value'] === null => 'targets.delete',
                $cell['version'] === null => 'targets.create',
                default => 'targets.update',
            };

            Gate::authorize($operation);
        }

        Activity::withoutRecording(function () use ($proposal, $plan, &$counts): void {
            foreach ($plan as [$cell, $indicator, $project, $target]) {
                if ($cell['value'] === null) {
                    if ($target !== null) {
                        $target->delete();
                        $counts['deleted']++;
                    }

                    continue;
                }

                $row = PlanIndicator::query()->firstOrNew([
                    'source_type' => self::SourceType,
                    'source_id' => $proposal->id,
                    'indicator_id' => $indicator->id,
                    'project_id' => $project->id,
                ]);

                $population = $cell['target_population'];

                if ($population === null && ! $row->exists && $indicator->measure === IndicatorMeasure::Percentage) {
                    $beneficiaries = $project->getRelation('pivot')->getAttribute('target_beneficiaries');
                    $population = $beneficiaries === null ? null : (string) $beneficiaries;
                }

                $row->fill([
                    'target' => $cell['value'],
                    'period' => $cell['period'],
                    'target_population' => $population ?? $row->target_population,
                ]);

                if (! $row->isDirty()) {
                    continue;
                }

                $counts[$row->exists ? 'updated' : 'created']++;
                $row->save();
            }
        });

        if (array_sum($counts) > 0) {
            $proposal->recordActivity('proposal.targets_saved', [], $counts);
        }

        return $counts;
    }

    /**
     * Shape-check every cell. Messages are keyed by cell so the grid can mark
     * each one; nothing is written when any cell fails.
     *
     * @param  array<int, mixed>  $cells
     * @return list<array{indicator_id: int, project_id: int, value: ?string, period: ?string, target_population: ?string, version: ?string}>
     *
     * @throws ValidationException
     */
    private function validateCells(array $cells): array
    {
        $data = Validator::make(['cells' => $cells], [
            'cells' => ['present', 'array', 'list'],
            'cells.*.indicator_id' => ['required', 'integer'],
            'cells.*.project_id' => ['required', 'integer'],
            'cells.*.value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'cells.*.period' => ['nullable', 'required_with:cells.*.value', Rule::enum(Period::class)],
            'cells.*.target_population' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'cells.*.version' => ['nullable', 'string'],
        ], [
            'cells.*.value.numeric' => __('targets.value_numeric'),
            'cells.*.value.min' => __('targets.value_negative'),
            'cells.*.value.max' => __('targets.value_too_large'),
            'cells.*.period.required_with' => __('targets.period_required'),
            'cells.*.period.enum' => __('targets.period_invalid'),
            'cells.*.target_population.numeric' => __('targets.value_numeric'),
            'cells.*.target_population.min' => __('targets.value_negative'),
            'cells.*.target_population.max' => __('targets.value_too_large'),
        ])->validate();

        return array_map(fn (array $cell): array => [
            'indicator_id' => (int) $cell['indicator_id'],
            'project_id' => (int) $cell['project_id'],
            'value' => isset($cell['value']) && $cell['value'] !== '' ? (string) $cell['value'] : null,
            'period' => isset($cell['value']) && $cell['value'] !== '' ? (string) $cell['period'] : null,
            'target_population' => isset($cell['target_population']) && $cell['target_population'] !== '' ? (string) $cell['target_population'] : null,
            'version' => isset($cell['version']) && $cell['version'] !== '' ? (string) $cell['version'] : null,
        ], array_values($data['cells']));
    }
}
