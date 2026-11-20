<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlanIndicator;
use App\Models\ProposalLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Y-10 backfill (D-80, D-90, D-91, wave4/AGENT.md §4): links every proposal
 * target to its location row and re-places it from that row.
 *
 * The row is the one of the target's proposal that carries its project, else
 * the one with the project's city and program. When two targets of one
 * indicator land on one row (an approval after Y-01 made one project per
 * center and MEAL filled both), neither is linked and both are listed:
 * merging targets is a human decision (D-80). A target that matches no row
 * is listed and left. `project_id` is not changed here.
 *
 * Reads `projects` through `DB::table` for the city and program fallback
 * only; that read goes away with Stage B (Y-17). `--dry-run` prints the same
 * counts and writes nothing. Idempotent: it only touches unlinked targets.
 */
class BackfillPlanIndicatorRows extends Command
{
    protected $signature = 'targets:row-backfill
        {--dry-run : Print the counts without writing anything}';

    protected $description = 'Link existing proposal targets to their location row (Y-10)';

    public function handle(): int
    {
        $plan = $this->plan();

        foreach ($this->counts($plan) as $key => $value) {
            $this->line(__('targets.console.row_backfill_'.$key, ['count' => $value]));
        }

        if ($plan['unlinked'] !== []) {
            $this->line(__('targets.console.row_backfill_unlinked_ids', ['ids' => implode(', ', $plan['unlinked'])]));
        }

        foreach ($plan['duplicates'] as $rowId => $targetIds) {
            $this->line(__('targets.console.row_backfill_duplicate_row', ['row' => $rowId, 'ids' => implode(', ', $targetIds)]));
        }

        if (! $this->option('dry-run')) {
            $this->apply($plan);
        }

        return self::SUCCESS;
    }

    /**
     * What to write for every proposal target with no row yet.
     *
     * @return array{link: array<int, array{row_id: int, columns: array{city_id: int|null, program_id: int|null, center_id: int|null, city_wide: bool, all_centers: bool}, changes_place: bool}>, unlinked: list<int>, duplicates: array<int, list<int>>}
     */
    public function plan(): array
    {
        $plan = ['link' => [], 'unlinked' => [], 'duplicates' => []];
        $hasColumn = Schema::hasColumn('plan_indicators', 'proposal_location_id');

        $targets = DB::table('plan_indicators')
            ->where('source_type', 'proposal')
            ->when($hasColumn, fn ($query) => $query->whereNull('proposal_location_id'))
            ->orderBy('id')
            ->get(['id', 'source_id', 'indicator_id', 'project_id', 'city_id', 'program_id', 'center_id', 'city_wide', 'all_centers']);

        if ($targets->isEmpty()) {
            return $plan;
        }

        $rows = ProposalLocation::query()
            ->with('centers')
            ->whereIn('proposal_id', $targets->pluck('source_id')->unique()->values())
            ->orderBy('id')
            ->get();
        $projects = DB::table('projects')
            ->whereIn('id', $targets->pluck('project_id')->filter()->unique()->values())
            ->get(['id', 'city_id', 'program_id'])
            ->keyBy('id');

        /** @var array<int, array<int, list<stdClass>>> $candidates row id => indicator id => targets */
        $candidates = [];

        foreach ($targets as $target) {
            $ofProposal = $rows->where('proposal_id', (int) $target->source_id);
            $row = $target->project_id === null ? null : $ofProposal->firstWhere('project_id', (int) $target->project_id);
            $project = $target->project_id === null ? null : $projects->get($target->project_id);

            if ($row === null && $project !== null) {
                $row = $ofProposal->first(fn (ProposalLocation $candidate): bool => $candidate->city_id === (int) $project->city_id && $candidate->program_id === (int) $project->program_id);
            }

            if ($row === null) {
                $plan['unlinked'][] = (int) $target->id;

                continue;
            }

            $candidates[$row->id][(int) $target->indicator_id][] = $target;
        }

        $taken = $hasColumn
            ? DB::table('plan_indicators')->whereIn('proposal_location_id', array_keys($candidates))->get(['id', 'indicator_id', 'proposal_location_id'])
            : collect();

        foreach ($candidates as $rowId => $byIndicator) {
            /** @var ProposalLocation $row */
            $row = $rows->firstWhere('id', $rowId);
            $columns = PlanIndicator::placeColumnsForRow($row);

            foreach ($byIndicator as $indicatorId => $group) {
                $already = $taken->where('proposal_location_id', $rowId)->where('indicator_id', $indicatorId)->pluck('id')->map(intval(...))->all();

                if (count($group) + count($already) > 1) {
                    $ids = [...$already, ...array_map(fn (stdClass $target): int => (int) $target->id, $group)];
                    sort($ids);
                    $plan['duplicates'][$rowId] = [...($plan['duplicates'][$rowId] ?? []), ...$ids];

                    continue;
                }

                $target = $group[0];
                $plan['link'][(int) $target->id] = [
                    'row_id' => $rowId,
                    'columns' => $columns,
                    'changes_place' => $this->placeOf($target) !== $columns,
                ];
            }
        }

        ksort($plan['link']);
        ksort($plan['duplicates']);

        return $plan;
    }

    /**
     * Write the plan: the row link and the row's place of each target.
     *
     * @param  array{link: array<int, array{row_id: int, columns: array{city_id: int|null, program_id: int|null, center_id: int|null, city_wide: bool, all_centers: bool}, changes_place: bool}>, unlinked: list<int>, duplicates: array<int, list<int>>}  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            foreach ($plan['link'] as $id => $link) {
                DB::table('plan_indicators')->where('id', $id)->update(['proposal_location_id' => $link['row_id'], ...$link['columns']]);
            }
        });
    }

    /**
     * @param  array{link: array<int, array{row_id: int, columns: array{city_id: int|null, program_id: int|null, center_id: int|null, city_wide: bool, all_centers: bool}, changes_place: bool}>, unlinked: list<int>, duplicates: array<int, list<int>>}  $plan
     * @return array{targets: int, linked: int, places_changed: int, unlinked: int, duplicates: int}
     */
    public function counts(array $plan): array
    {
        $duplicates = array_sum(array_map(count(...), $plan['duplicates']));

        return [
            'targets' => count($plan['link']) + count($plan['unlinked']) + $duplicates,
            'linked' => count($plan['link']),
            'places_changed' => count(array_filter($plan['link'], fn (array $link): bool => $link['changes_place'])),
            'unlinked' => count($plan['unlinked']),
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @return array{city_id: int|null, program_id: int|null, center_id: int|null, city_wide: bool, all_centers: bool}
     */
    private function placeOf(stdClass $target): array
    {
        return [
            'city_id' => $target->city_id === null ? null : (int) $target->city_id,
            'program_id' => $target->program_id === null ? null : (int) $target->program_id,
            'center_id' => $target->center_id === null ? null : (int) $target->center_id,
            'city_wide' => (bool) $target->city_wide,
            'all_centers' => (bool) $target->all_centers,
        ];
    }
}
