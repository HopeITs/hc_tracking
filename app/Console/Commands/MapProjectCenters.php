<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrPosition;
use App\Models\HrProgram;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Proposes an HR center for every project (D-30) and, with --apply, records it.
 *
 * A project's center is the active center in the project's city that has an
 * active HR position for the project's program. One such center is a match;
 * several are ambiguous; none leaves the project without a center (HR adds
 * centers for the missing cities, D-30c). Two open projects that would end up
 * on the same (center, program) are reported as conflicts and never applied
 * (D-30a). A center already set is kept unless --force.
 *
 * The report also lists HR programs sharing a code (D-10a) and whether the
 * pillar programs HR must add exist yet (D-35a). HR is only read; a dry run
 * (the default) writes nothing.
 */
class MapProjectCenters extends Command
{
    /**
     * The programs D-35a asks HR to add, matched by name.
     *
     * @var list<string>
     */
    private const PillarPrograms = ['Wells', 'Alternative Energy', 'PSS', 'Case Management', 'Parenting'];

    /**
     * @var string
     */
    protected $signature = 'hr:map-project-centers
        {--dry-run : Only report; the default when --apply is not given}
        {--apply : Record the matched centers on the projects}
        {--force : Also re-map projects that already have a center}';

    /**
     * @var string
     */
    protected $description = 'Propose (and with --apply record) the HR center of every project';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->components->info($apply ? 'Applying center mapping'.($force ? ' (overwriting existing centers)' : '').'.' : 'Dry run: nothing will be written.');

        $centers = HrCenter::query()->toBase()->get(['id', 'name_en', 'city_id', 'is_active'])->keyBy('id');
        $centerIdsByCityAndProgram = $this->centerIdsByCityAndProgram($centers);
        $cityNames = HrCity::query()->toBase()->pluck('name_en', 'id');
        $programNames = HrProgram::namesById();

        $projects = Project::query()->orderBy('id')->get(['id', 'name', 'status', 'city_id', 'center_id', 'program_id']);

        $proposals = [];
        $ambiguous = [];
        $withoutCenter = [];
        $skipped = 0;

        foreach ($projects as $project) {
            if ($project->center_id !== null && ! $force) {
                $skipped++;

                continue;
            }

            $candidates = $centerIdsByCityAndProgram[$project->city_id.':'.$project->program_id] ?? [];

            if ($candidates === []) {
                $withoutCenter[] = $project;
            } elseif (count($candidates) === 1) {
                $proposals[$project->id] = $candidates[0];
            } else {
                $ambiguous[] = [$project, $candidates];
            }
        }

        $conflicts = $this->conflicts($projects, $proposals, $force);
        $matched = array_diff_key($proposals, $conflicts);

        $label = fn (Project $project): string => '#'.$project->id.' '.$project->name;
        $centerName = fn (int $centerId): string => (string) ($centers[$centerId]->name_en ?? '#'.$centerId);
        $cityName = fn (Project $project): string => (string) ($cityNames[$project->city_id] ?? '#'.$project->city_id);
        $programName = fn (Project $project): string => $programNames[$project->program_id] ?? '#'.$project->program_id;
        $byId = $projects->keyBy('id');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Matched</>', (string) count($matched));
        $this->table(['Project', 'Program', 'City', 'Center'], array_map(
            fn (int $projectId, int $centerId): array => [$label($byId[$projectId]), $programName($byId[$projectId]), $cityName($byId[$projectId]), $centerName($centerId)],
            array_keys($matched),
            $matched,
        ));

        $this->components->twoColumnDetail('<fg=yellow>Ambiguous (several centers)</>', (string) count($ambiguous));
        $this->table(['Project', 'Program', 'City', 'Candidate centers'], array_map(
            fn (array $row): array => [$label($row[0]), $programName($row[0]), $cityName($row[0]), implode(', ', array_map($centerName, $row[1]))],
            $ambiguous,
        ));

        $this->components->twoColumnDetail('<fg=red>No center</>', (string) count($withoutCenter));
        $this->table(['Project', 'Program', 'City', 'Active centers in the city'], array_map(
            fn (Project $project): array => [
                $label($project),
                $programName($project),
                $cityName($project),
                $centers->filter(fn (stdClass $center): bool => (int) $center->city_id === $project->city_id && (bool) $center->is_active)
                    ->map(fn (stdClass $center): string => (string) $center->name_en)
                    ->implode(', ') ?: '—',
            ],
            $withoutCenter,
        ));

        $this->components->twoColumnDetail('<fg=red>Conflicts (open projects on one center and program)</>', (string) count($conflicts));
        $this->table(['Project', 'Program', 'Center', 'Status'], array_map(
            fn (int $projectId, int $centerId): array => [$label($byId[$projectId]), $programName($byId[$projectId]), $centerName($centerId), $byId[$projectId]->status->value],
            array_keys($conflicts),
            $conflicts,
        ));

        $this->components->twoColumnDetail('Already mapped (kept; --force to re-map)', (string) $skipped);

        $this->reportPrograms();

        if ($apply) {
            $updated = $this->apply($matched, $byId);
            $this->components->info("Updated {$updated} project(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Active center ids by "city:program", from active HR positions at active centers.
     *
     * @param  Collection<array-key, stdClass>  $centers
     * @return array<string, list<int>>
     */
    private function centerIdsByCityAndProgram(Collection $centers): array
    {
        $pairs = HrPosition::query()
            ->toBase()
            ->where('is_active', true)
            ->whereNotNull('center_id')
            ->whereNotNull('program_id')
            ->distinct()
            ->get(['center_id', 'program_id']);

        $map = [];

        foreach ($pairs as $pair) {
            $center = $centers[(int) $pair->center_id] ?? null;

            if ($center === null || ! (bool) $center->is_active) {
                continue;
            }

            $map[(int) $center->city_id.':'.(int) $pair->program_id][] = (int) $center->id;
        }

        foreach ($map as $key => $centerIds) {
            $centerIds = array_values(array_unique($centerIds));
            sort($centerIds);
            $map[$key] = $centerIds;
        }

        return $map;
    }

    /**
     * The proposals that would put a second open project on a (center, program)
     * pair, counting projects that already hold a center and are not re-mapped.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<int, int>  $proposals  project id => proposed center id
     * @return array<int, int> project id => proposed center id
     */
    private function conflicts(Collection $projects, array $proposals, bool $force): array
    {
        $openByPair = [];

        foreach ($projects as $project) {
            if (! $project->status->isOpen()) {
                continue;
            }

            $centerId = $proposals[$project->id] ?? ($force ? null : $project->center_id);

            if ($centerId !== null) {
                $openByPair[$centerId.':'.$project->program_id][] = $project->id;
            }
        }

        $conflicts = [];

        foreach ($openByPair as $projectIds) {
            if (count($projectIds) < 2) {
                continue;
            }

            foreach ($projectIds as $projectId) {
                if (isset($proposals[$projectId])) {
                    $conflicts[$projectId] = $proposals[$projectId];
                }
            }
        }

        ksort($conflicts);

        return $conflicts;
    }

    /**
     * Record the matched centers. The city already is the center's city, since
     * the match was made within it.
     *
     * @param  array<int, int>  $matched  project id => center id
     * @param  Collection<int, Project>  $projects
     */
    private function apply(array $matched, Collection $projects): int
    {
        return DB::transaction(function () use ($matched, $projects): int {
            $updated = 0;

            foreach ($matched as $projectId => $centerId) {
                if ($projects[$projectId]->center_id === $centerId) {
                    continue;
                }

                $updated += Project::query()->whereKey($projectId)->update(['center_id' => $centerId]);
            }

            return $updated;
        });
    }

    /**
     * HR programs sharing a code (D-10a) and the pillar programs HR must add (D-35a).
     */
    private function reportPrograms(): void
    {
        $programs = HrProgram::query()->toBase()->orderBy('code')->orderBy('id')->get(['id', 'name', 'code']);

        $duplicates = $programs->groupBy('code')->filter(fn (Collection $group): bool => $group->count() > 1);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Duplicated program codes (D-10a)</>', (string) $duplicates->count());
        $this->table(['Code', 'Programs'], $duplicates->map(fn (Collection $group, string $code): array => [
            $code,
            $group->map(fn (stdClass $program): string => trim((string) $program->name))->implode(', '),
        ])->values()->all());

        $this->table(['Program (D-35a)', 'In HR', 'Code'], array_map(function (string $name) use ($programs): array {
            $found = $programs->filter(fn (stdClass $program): bool => str_contains(mb_strtolower((string) $program->name), mb_strtolower($name)));

            return [$name, $found->isEmpty() ? 'no' : 'yes', $found->isEmpty() ? '—' : $found->pluck('code')->implode(', ')];
        }, self::PillarPrograms));
    }
}
