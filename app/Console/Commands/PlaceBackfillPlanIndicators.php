<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Y-08 backfill (D-75, D-82, D-85, D-88, D-90, wave4/AGENT.md §4): gives every
 * target that has a project its own place.
 *
 * City and program come from the project; the center from the project's own
 * `center_id`, else, for a proposal target, from the proposal's location row
 * that carries the project: one center gives that center, a city-wide row
 * gives `city_wide`, several centers give a shared target (`all_centers`).
 * A target whose project center and row disagree, or a proposal target whose
 * project matches no row while the project has no center, is left with the
 * center not set and listed. Nothing is guessed (D-80). Annual plan targets
 * never read a location row.
 *
 * This and the Y-08 migration are the only places that read `projects` for a
 * target (D-74), so the logic lives here once and the migration calls it.
 * `--dry-run` prints the same counts and writes nothing. Idempotent: it only
 * touches targets with a project and no place yet.
 */
class PlaceBackfillPlanIndicators extends Command
{
    protected $signature = 'targets:place-backfill
        {--dry-run : Print the counts without writing anything}';

    protected $description = 'Give existing per-center targets their place (Y-08)';

    public function handle(): int
    {
        $plan = $this->plan();
        $counts = $this->counts($plan);

        foreach ($counts as $key => $value) {
            $this->line(__('targets.console.backfill_'.$key, ['count' => $value]));
        }

        $this->listIds('shared_ids', $plan, fn (array $row): bool => $row['all_centers']);
        $this->listIds('without_center_ids', $plan, fn (array $row): bool => $row['center_id'] === null && ! $row['city_wide'] && ! $row['all_centers']);
        $this->listIds('disagree_ids', $plan, fn (array $row): bool => $row['disagree']);
        $this->listIds('no_row_ids', $plan, fn (array $row): bool => $row['no_row']);

        if (! $this->option('dry-run')) {
            $this->apply($plan);
        }

        return self::SUCCESS;
    }

    /**
     * The answer for every target that has a project and no place yet.
     *
     * @return array<int, array{source_type: string, project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, all_centers: bool, center_ids: list<int>, disagree: bool, no_row: bool}>
     */
    public function plan(): array
    {
        $targets = DB::table('plan_indicators')
            ->whereNotNull('project_id')
            ->when(Schema::hasColumn('plan_indicators', 'city_id'), fn ($query) => $query->whereNull('city_id'))
            ->orderBy('id')
            ->get(['id', 'source_type', 'source_id', 'project_id']);

        $plan = [];

        foreach ($targets as $target) {
            $project = DB::table('projects')->where('id', $target->project_id)->first(['city_id', 'program_id', 'center_id']);

            if ($project === null || $project->program_id === null || $project->city_id === null) {
                continue;
            }

            $projectCenter = $project->center_id === null ? null : (int) $project->center_id;
            $answers = $this->rowAnswers($target->source_type === 'proposal' ? (int) $target->source_id : null, (int) $target->project_id);

            $centerId = $projectCenter;
            $cityWide = false;
            $allCenters = false;
            $centerIds = [];
            $disagree = false;
            $noRow = false;

            if ($projectCenter !== null) {
                $disagree = $target->source_type === 'proposal'
                    && collect($answers)->contains(fn (array $answer): bool => $answer['center_ids'] !== [] && ! in_array($projectCenter, $answer['center_ids'], true));
                $centerId = $disagree ? null : $projectCenter;
            } else {
                $noRow = $target->source_type === 'proposal' && $answers === [];
                $disagree = count($answers) > 1;

                if ($answers !== [] && ! $disagree) {
                    $answer = array_values($answers)[0];
                    $cityWide = $answer['city_wide'];
                    $centerIds = $answer['center_ids'];
                    $allCenters = count($centerIds) > 1;
                    $centerId = count($centerIds) === 1 ? $centerIds[0] : null;
                }
            }

            $plan[(int) $target->id] = [
                'source_type' => (string) $target->source_type,
                'project_id' => (int) $target->project_id,
                'city_id' => (int) $project->city_id,
                'program_id' => (int) $project->program_id,
                'center_id' => $centerId,
                'city_wide' => $cityWide,
                'all_centers' => $allCenters,
                'center_ids' => $centerIds,
                'disagree' => $disagree,
                'no_row' => $noRow,
            ];
        }

        return $plan;
    }

    /**
     * Write the plan: the place columns of each target.
     *
     * @param  array<int, array{source_type: string, project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, all_centers: bool, center_ids: list<int>, disagree: bool, no_row: bool}>  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            foreach ($plan as $id => $row) {
                DB::table('plan_indicators')->where('id', $id)->update([
                    'city_id' => $row['city_id'],
                    'program_id' => $row['program_id'],
                    'center_id' => $row['center_id'],
                    'city_wide' => $row['city_wide'],
                    'all_centers' => $row['all_centers'],
                ]);
            }
        });
    }

    /**
     * @param  array<int, array{source_type: string, project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, all_centers: bool, center_ids: list<int>, disagree: bool, no_row: bool}>  $plan
     * @return array{targets: int, with_place: int, with_center: int, city_wide: int, shared: int, without_center: int, without_project: int}
     */
    public function counts(array $plan): array
    {
        $rows = collect($plan);
        $withoutProject = DB::table('plan_indicators')->whereNull('project_id')->count();

        return [
            'targets' => $rows->count() + $withoutProject,
            'with_place' => $rows->count(),
            'with_center' => $rows->filter(fn (array $row): bool => $row['center_id'] !== null)->count(),
            'city_wide' => $rows->filter(fn (array $row): bool => $row['city_wide'])->count(),
            'without_center' => $rows->filter(fn (array $row): bool => $row['center_id'] === null && ! $row['city_wide'] && ! $row['all_centers'])->count(),
            'shared' => $rows->filter(fn (array $row): bool => $row['all_centers'])->count(),
            'without_project' => $withoutProject,
        ];
    }

    /**
     * What each row carrying the project says, de-duplicated by its centers:
     * the proposal's rows for a proposal target, every row for an annual-plan
     * target (as on main, Dev 1, 17 Nov).
     *
     * @return array<string, array{center_ids: list<int>, city_wide: bool}>
     */
    private function rowAnswers(?int $proposalId, int $projectId): array
    {
        $answers = [];
        $rows = DB::table('proposal_locations')
            ->where('project_id', $projectId)
            ->when($proposalId !== null, fn ($query) => $query->where('proposal_id', $proposalId))
            ->get(['id', 'city_wide']);

        foreach ($rows as $row) {
            $centerIds = array_values(array_map(intval(...), DB::table('proposal_location_centers')->where('proposal_location_id', $row->id)->orderBy('center_id')->pluck('center_id')->all()));
            $cityWide = (bool) $row->city_wide;

            $answers[$cityWide ? 'cw' : implode('-', $centerIds)] = ['center_ids' => $cityWide ? [] : $centerIds, 'city_wide' => $cityWide];
        }

        return $answers;
    }

    /**
     * @param  array<int, array{source_type: string, project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, all_centers: bool, center_ids: list<int>, disagree: bool, no_row: bool}>  $plan
     * @param  callable(array{source_type: string, project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, all_centers: bool, center_ids: list<int>, disagree: bool, no_row: bool}): bool  $filter
     */
    private function listIds(string $key, array $plan, callable $filter): void
    {
        $rows = array_filter($plan, $filter);

        if ($rows === []) {
            return;
        }

        $labels = array_map(
            fn (int $id, array $row): string => $key === 'shared_ids' ? $id.' ('.implode('/', $row['center_ids']).')' : (string) $id,
            array_keys($rows),
            array_values($rows),
        );

        $this->line(__('targets.console.backfill_'.$key, ['ids' => implode(', ', $labels)]));
    }
}
