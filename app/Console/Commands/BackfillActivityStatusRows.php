<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\ActivityStatusRowBackfill;
use Illuminate\Console\Command;

/**
 * Move every confirmed activity status onto the location row of its
 * activity's own proposal that carries its project (Y-15 slice 2, D-93), and
 * with --dry-run only report it. The dry run does the same work inside a
 * rolled-back transaction, so it prints the exact counts a real run would
 * write (wave4/AGENT.md §4).
 *
 * The twin of the `backfill_project_activity_statuses_proposal_location_id`
 * migration: both go through `ActivityStatusRowBackfill`, so the rule lives
 * in one place. Safe to re-run. Statuses it cannot map are listed by id and
 * left alone (D-80).
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillActivityStatusRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'activity-statuses:row-backfill {--dry-run : Only report; write nothing}';

    /**
     * @var string
     */
    protected $description = 'Give every confirmed activity status the location row of its own proposal that carries its project (Y-15)';

    public function handle(ActivityStatusRowBackfill $backfill): int
    {
        $apply = ! $this->option('dry-run');

        $this->components->info($apply ? __('monthly_reports.console.status_backfill_applying') : __('monthly_reports.console.status_backfill_dry_run'));

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(__('monthly_reports.console.status_backfill_seen'), (string) $counts['statuses_seen']);
        $this->components->twoColumnDetail('<fg=green>'.__('monthly_reports.console.status_backfill_moved').'</>', (string) $counts['moved']);
        $this->components->twoColumnDetail(__('monthly_reports.console.status_backfill_by_own_proposal'), (string) $counts['by_own_proposal']);
        $this->components->twoColumnDetail(__('monthly_reports.console.status_backfill_by_date'), (string) $counts['by_date']);
        $this->components->twoColumnDetail(__('monthly_reports.console.status_backfill_by_most_recent'), (string) $counts['by_most_recent']);

        $this->newLine();
        $this->line(__('monthly_reports.console.status_backfill_rows', ['rows' => $this->rows($counts['rows'])]));
        $this->line(__('monthly_reports.console.status_backfill_unmapped', ['ids' => $this->ids($counts['unmapped'])]));

        return self::SUCCESS;
    }

    /**
     * The statuses each row took, as `row id × count`.
     *
     * @param  array<int, int>  $rows
     */
    private function rows(array $rows): string
    {
        if ($rows === []) {
            return __('monthly_reports.console.status_backfill_none');
        }

        ksort($rows);

        return implode(', ', array_map(
            fn (int $count, int $rowId): string => "{$rowId}×{$count}",
            $rows,
            array_keys($rows),
        ));
    }

    /**
     * @param  list<int>  $ids
     */
    private function ids(array $ids): string
    {
        return $ids === [] ? __('monthly_reports.console.status_backfill_none') : implode(', ', $ids);
    }
}
