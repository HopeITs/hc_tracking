<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\LocationBackfill;
use Illuminate\Console\Command;

/**
 * Make every location row a faithful copy of the project it carries
 * (Y-11, D-92, D-93, D-94) and, with --apply, record it. A dry run (the
 * default, and whenever --dry-run is given) does the same work inside a
 * rolled-back transaction, so it prints the same counts and writes nothing.
 *
 * Safe to re-run: only a row this run would change is counted and written.
 * Projects no row carries are listed and left alone (D-80) — each needs a
 * proposal first (D-84, D-87), which is a human step, and the second Y-11
 * migration refuses while any remain.
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillLocationsFromProjects extends Command
{
    /**
     * @var string
     */
    protected $signature = 'locations:backfill-from-projects
        {--dry-run : Only report; the default when --apply is not given}
        {--apply : Write the rows}';

    /**
     * @var string
     */
    protected $description = 'Give every location row what the project it carries carries (Y-11)';

    public function handle(LocationBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');

        $this->components->info($apply ? 'Applying: location rows will be written.' : 'Dry run: nothing will be written.');

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=green>'.__('projects.console.location_backfill_rows', ['count' => $counts['rows_filled']]).'</>',
            (string) $counts['rows_filled'],
        );

        foreach ($counts['rows_by_status'] as $status => $count) {
            $this->components->twoColumnDetail(__('projects.console.location_backfill_status', ['status' => $status, 'count' => $count]), (string) $count);
        }

        $this->components->twoColumnDetail(
            __('projects.console.location_backfill_files', [
                'count' => $counts['files_mirrored'],
                'by_date' => $counts['files_by_date'],
                'by_recent' => $counts['files_by_most_recent'],
            ]),
            (string) $counts['files_mirrored'],
        );
        $this->components->twoColumnDetail(__('projects.console.location_backfill_sectors', ['count' => $counts['sectors_copied']]), (string) $counts['sectors_copied']);
        $this->components->twoColumnDetail(__('projects.console.location_backfill_acknowledgements', ['count' => $counts['acknowledgements_copied']]), (string) $counts['acknowledgements_copied']);
        $this->components->twoColumnDetail(__('projects.console.location_backfill_slugs', ['count' => $counts['slugs_written']]), (string) $counts['slugs_written']);

        $this->newLine();
        $this->line(__('projects.console.location_backfill_several_rows', ['ids' => $this->ids($counts['projects_on_several_rows'])]));
        $this->line(__('projects.console.location_backfill_without_row', ['ids' => $this->ids($counts['projects_without_row'])]));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     */
    private function ids(array $ids): string
    {
        return $ids === [] ? __('projects.console.location_backfill_none') : implode(', ', $ids);
    }
}
