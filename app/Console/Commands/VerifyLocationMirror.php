<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProposalLocation;
use App\Support\Locations\ProjectLocationMirror;
use Illuminate\Console\Command;

/**
 * Check that every location row still carries what its project carries
 * (Y-11 slice 2, wave4/AGENT.md §8.2).
 *
 * It compares every mirrored column, the sector set and the owner's
 * acknowledgement, prints each difference in words, and **exits 1** when any
 * exists. It is the Start-state check of Y-12…Y-17: run it before and after,
 * and paste both.
 *
 * Read-only: it never repairs anything. A difference means a writer reached
 * `projects` without going through ProjectLocationMirror, which is the bug to
 * fix — `locations:backfill-from-projects --apply` only hides it.
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class VerifyLocationMirror extends Command
{
    /**
     * @var string
     */
    protected $signature = 'locations:verify-mirror
        {--project= : Check one project id instead of all of them}';

    /**
     * @var string
     */
    protected $description = 'Check every location row still mirrors its project (Y-11); exits 1 on any difference';

    public function handle(ProjectLocationMirror $mirror): int
    {
        $projectIds = $this->projectIds();

        if ($projectIds === []) {
            $this->components->info(__('projects.console.verify_mirror_nothing'));

            return self::SUCCESS;
        }

        $differences = [];
        $checked = 0;

        foreach (array_chunk($projectIds, 100) as $chunk) {
            foreach (Project::query()->whereIn('id', $chunk)->orderBy('id')->get() as $project) {
                $checked++;

                foreach ($mirror->differences($project) as $difference) {
                    $differences[] = $difference;
                }
            }
        }

        if ($differences === []) {
            $this->components->info(__('projects.console.verify_mirror_clean', ['projects' => $checked]));

            return self::SUCCESS;
        }

        $this->components->error(__('projects.console.verify_mirror_dirty', [
            'count' => count($differences),
            'projects' => $checked,
        ]));

        foreach ($differences as $difference) {
            $this->line('  '.$difference);
        }

        $this->newLine();
        $this->line(__('projects.console.verify_mirror_hint'));

        return self::FAILURE;
    }

    /**
     * The projects to check: the one asked for, else every project a row
     * carries (a project no row carries has nothing to mirror and is the
     * backfill's business, not this command's).
     *
     * @return list<int>
     */
    private function projectIds(): array
    {
        $one = $this->option('project');

        if ($one !== null) {
            return [(int) $one];
        }

        return array_values(ProposalLocation::query()
            ->whereNotNull('project_id')
            ->distinct()
            ->orderBy('project_id')
            ->pluck('project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
