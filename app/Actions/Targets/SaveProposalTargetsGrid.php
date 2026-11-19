<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save a proposal's targets grid in one go: one target per indicator per
 * location row (contracts §12.1, D-23, D-25, D-50a, D-90, D-91).
 *
 * - Who: MEAL. Creating a cell needs `targets.create`, changing one
 *   `targets.update`, clearing one `targets.delete`; `proposals.update`
 *   alone grants nothing. Terminal proposals are read-only.
 * - Scope (BOLA): every indicator is an instance under this proposal's
 *   framework and every `location_id` a row of this proposal; one foreign id
 *   rejects the whole batch. A cell naming `project_id` is refused (Y-10).
 *   A row still waiting for its program takes no new or changed value.
 * - Place: a new target takes its row's place (PlanIndicator::placeColumnsForRow:
 *   one center, field work, or shared by several centers) and the row's
 *   `project_id`. Later row changes reach it through syncPlaceFromRow().
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
     * Whether a row still waits for its program: it has none yet, or its
     * program request is pending (Y-10). Such a row takes no new or changed
     * target; the grid shows its column disabled with the reason. Load
     * `programRequest` on the row first.
     */
    public static function isWaitingForProgram(ProposalLocation $row): bool
    {
        return $row->program_id === null || ($row->programRequest?->isPending() ?? false);
    }

    /**
     * The version of a saved cell, as the grid sends it back.
     */
    public static function versionOf(PlanIndicator $target): string
    {
        return (string) $target->updated_at?->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<array{indicator_id?: mixed, location_id?: mixed, value?: mixed, period?: mixed, target_population?: mixed, version?: mixed}>  $cells
     * @return array{created: int, updated: int, deleted: int}
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, array $cells): array
    {
        Gate::authorize('targets.update');

        $cells = $this->validateCells($cells);

        return DB::transaction(function () use ($proposal, $cells): array {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPayroll()) {
                throw ValidationException::withMessages(['proposal' => __('targets.payroll_has_no_targets')]);
            }

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages(['proposal' => __('targets.proposal_read_only')]);
            }

            $indicators = Indicator::query()
                ->whereIn('id', array_unique(array_column($cells, 'indicator_id')))
                ->whereIn('result_id', Result::query()->proposalFramework($locked->id)->select('id'))
                ->with('result:id,program_id,proposal_id,type')
                ->get()
                ->keyBy('id');

            /** @var Collection<int, ProposalLocation> $rows */
            $rows = $locked->locations()->with(['centers', 'programRequest'])->get()->keyBy('id');

            $existing = PlanIndicator::query()
                ->where('source_type', self::SourceType)
                ->where('source_id', $locked->id)
                ->whereIn('indicator_id', $indicators->keys())
                ->whereNotNull('proposal_location_id')
                ->withCount('reports')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (PlanIndicator $target): string => $target->indicator_id.':'.$target->proposal_location_id);

            $errors = [];
            $plan = [];

            foreach ($cells as $index => $cell) {
                if ($cell['has_project_id']) {
                    $errors["cells.{$index}"] = __('targets.cell_project_not_accepted');

                    continue;
                }

                $indicator = $indicators->get($cell['indicator_id']);
                $row = $rows->get($cell['location_id']);

                if ($indicator === null || $row === null) {
                    $errors["cells.{$index}"] = __('targets.cell_outside_proposal');

                    continue;
                }

                /** @var PlanIndicator|null $target */
                $target = $existing->get($indicator->id.':'.$row->id);

                if (($target === null ? null : self::versionOf($target)) !== $cell['version']) {
                    $errors["cells.{$index}"] = __('targets.cell_stale');

                    continue;
                }

                $refusal = $this->refusalFor($indicator, $row, $target, $cell['value']);

                if ($refusal !== null) {
                    $errors["cells.{$index}"] = $refusal;

                    continue;
                }

                $plan[] = [$cell, $indicator, $row, $target];
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
    private function refusalFor(Indicator $indicator, ProposalLocation $row, ?PlanIndicator $target, ?string $value): ?string
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

        // A row waiting for its program takes no new or changed target;
        // clearing one (above) is still allowed (Y-10, pending Dev 1).
        if (self::isWaitingForProgram($row) && ($target === null || (float) $target->target !== (float) $value)) {
            return __('targets.cell_row_waiting_program');
        }

        if ($indicator->measure === IndicatorMeasure::Percentage && (float) $value > 100) {
            return __('targets.percentage_over_100');
        }

        // The one chain rule that still applies to a row target, compared by
        // id so no project is read (D-74): an indicator tied to a project
        // takes targets only on the row that carries that project.
        if ($target === null && $indicator->project_id !== null && $indicator->project_id !== $row->project_id) {
            $issue = __('targets.issue_project_differs_from_indicator');

            return __('targets.cell_chain_issue', ['issue' => is_string($issue) ? $issue : 'targets.issue_project_differs_from_indicator']);
        }

        return null;
    }

    /**
     * Apply the checked cells and record the summary.
     *
     * @param  list<array{0: array{indicator_id: int, location_id: int, value: ?string, period: ?string, target_population: ?string, version: ?string, has_project_id: bool}, 1: Indicator, 2: ProposalLocation, 3: ?PlanIndicator}>  $plan
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

        $beneficiaries = $this->beneficiariesByProject($proposal, $plan);

        Activity::withoutRecording(function () use ($proposal, $plan, $beneficiaries, &$counts): void {
            foreach ($plan as [$cell, $indicator, $row, $target]) {
                if ($cell['value'] === null) {
                    if ($target !== null) {
                        $target->delete();
                        $counts['deleted']++;
                    }

                    continue;
                }

                $target ??= new PlanIndicator([
                    'source_type' => self::SourceType,
                    'source_id' => $proposal->id,
                    'indicator_id' => $indicator->id,
                    'proposal_location_id' => $row->id,
                    'project_id' => $row->project_id,
                    ...PlanIndicator::placeColumnsForRow($row),
                ]);

                $population = $cell['target_population'];

                if ($population === null && ! $target->exists && $indicator->measure === IndicatorMeasure::Percentage && $row->project_id !== null) {
                    $default = $beneficiaries[$row->project_id] ?? null;
                    $population = $default === null ? null : (string) $default;
                }

                $target->fill([
                    'target' => $cell['value'],
                    'period' => $cell['period'],
                    'target_population' => $population ?? $target->target_population,
                ]);

                if (! $target->isDirty()) {
                    continue;
                }

                $counts[$target->exists ? 'updated' : 'created']++;
                $target->save();
            }
        });

        if (array_sum($counts) > 0) {
            $proposal->recordActivity('proposal.targets_saved', [], $counts);
        }

        return $counts;
    }

    /**
     * `project_proposal.target_beneficiaries` by project: the default display
     * base of a new percentage target (D-25). Read once per save, only when a
     * new percentage cell needs it. Moves to the location row in Y-11.
     *
     * @param  list<array{0: array{indicator_id: int, location_id: int, value: ?string, period: ?string, target_population: ?string, version: ?string, has_project_id: bool}, 1: Indicator, 2: ProposalLocation, 3: ?PlanIndicator}>  $plan
     * @return array<int, int|null>
     */
    private function beneficiariesByProject(Proposal $proposal, array $plan): array
    {
        $needed = collect($plan)->contains(fn (array $step): bool => $step[0]['value'] !== null
            && $step[0]['target_population'] === null
            && $step[3] === null
            && $step[1]->measure === IndicatorMeasure::Percentage
            && $step[2]->project_id !== null);

        if (! $needed) {
            return [];
        }

        return DB::table('project_proposal')
            ->where('proposal_id', $proposal->id)
            ->pluck('target_beneficiaries', 'project_id')
            ->map(fn (mixed $value): ?int => $value === null ? null : (int) $value)
            ->all();
    }

    /**
     * Shape-check every cell. Messages are keyed by cell so the grid can mark
     * each one; nothing is written when any cell fails.
     *
     * @param  array<int, mixed>  $cells
     * @return list<array{indicator_id: int, location_id: int, value: ?string, period: ?string, target_population: ?string, version: ?string, has_project_id: bool}>
     *
     * @throws ValidationException
     */
    private function validateCells(array $cells): array
    {
        $data = Validator::make(['cells' => $cells], [
            'cells' => ['present', 'array', 'list'],
            'cells.*.indicator_id' => ['required', 'integer'],
            'cells.*.location_id' => ['required_without:cells.*.project_id', 'nullable', 'integer'],
            'cells.*.project_id' => ['nullable'],
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
            'location_id' => (int) ($cell['location_id'] ?? 0),
            'value' => isset($cell['value']) && $cell['value'] !== '' ? (string) $cell['value'] : null,
            'period' => isset($cell['value']) && $cell['value'] !== '' ? (string) $cell['period'] : null,
            'target_population' => isset($cell['target_population']) && $cell['target_population'] !== '' ? (string) $cell['target_population'] : null,
            'version' => isset($cell['version']) && $cell['version'] !== '' ? (string) $cell['version'] : null,
            'has_project_id' => array_key_exists('project_id', $cell),
        ], array_values($data['cells']));
    }
}
