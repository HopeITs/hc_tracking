<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\TaskRowBackfill;
use Illuminate\Console\Command;

/**
 * Move every task raised against a project onto the location row that carries
 * it (Y-12, D-93, D-94) and, with --apply, record it. A dry run (the default,
 * and whenever --dry-run is given) does the same work inside a rolled-back
 * transaction, so it prints the same counts and writes nothing.
 *
 * Safe to re-run: a task already on a row is no longer a `project` source.
 * Tasks whose project no row carries are listed and left (D-80).
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillTaskRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'tasks:row-backfill
        {--dry-run : Only report; the default when --apply is not given}
        {--apply : Write the new sources}';

    /**
     * @var string
     */
    protected $description = 'Move project tasks onto the location row their date picks (Y-12)';

    public function handle(TaskRowBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');

        $this->components->info($apply ? 'Applying: task sources will be rewritten.' : 'Dry run: nothing will be written.');

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(__('tasks.console.row_backfill_tasks'), (string) $counts['tasks']);
        $this->components->twoColumnDetail('<fg=green>'.__('tasks.console.row_backfill_moved').'</>', (string) $counts['moved']);
        $this->components->twoColumnDetail(__('tasks.console.row_backfill_by_date'), (string) $counts['by_date']);
        $this->components->twoColumnDetail(__('tasks.console.row_backfill_by_recent'), (string) $counts['by_most_recent']);
        $this->components->twoColumnDetail(__('tasks.console.row_backfill_subtasks'), (string) $counts['subtasks_followed']);

        $this->newLine();
        $this->line(__('tasks.console.row_backfill_unmapped', ['ids' => $this->ids($counts['unmapped'])]));
        $this->line(__('tasks.console.row_backfill_rowless_projects', ['ids' => $this->ids($counts['projects_without_row'])]));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     */
    private function ids(array $ids): string
    {
        return $ids === [] ? __('tasks.console.row_backfill_none') : implode(', ', $ids);
    }
}
