<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\ScheduleRowBackfill;
use Illuminate\Console\Command;

/**
 * Move schedule dependencies and baselines onto location rows (Y-12, D-93)
 * and, with --apply, record it. A dry run (the default, and whenever
 * --dry-run is given) does the same work inside a rolled-back transaction, so
 * it prints the same counts and writes nothing.
 *
 * **It deletes dependencies**: a link whose two ends land on different rows
 * cannot be honoured. Every deleted id is printed. Run the dry run first and
 * read that list before applying.
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillScheduleRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'schedule:row-backfill
        {--dry-run : Only report; the default when --apply is not given}
        {--apply : Write the rows and delete the links that cannot be honoured}';

    /**
     * @var string
     */
    protected $description = 'Move schedule dependencies and baselines onto location rows (Y-12)';

    public function handle(ScheduleRowBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');

        $this->components->info($apply
            ? 'Applying: rows will be written and cross-row links deleted.'
            : 'Dry run: nothing will be written.');

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(__('tasks.console.schedule_dependencies'), (string) $counts['dependencies']);
        $this->components->twoColumnDetail('<fg=green>'.__('tasks.console.schedule_dependencies_moved').'</>', (string) $counts['dependencies_moved']);
        $this->components->twoColumnDetail(__('tasks.console.schedule_baselines'), (string) $counts['baselines']);
        $this->components->twoColumnDetail('<fg=green>'.__('tasks.console.schedule_baselines_moved').'</>', (string) $counts['baselines_moved']);
        $this->components->twoColumnDetail(__('tasks.console.schedule_baselines_by_date'), (string) $counts['baselines_by_date']);
        $this->components->twoColumnDetail(__('tasks.console.schedule_baselines_by_recent'), (string) $counts['baselines_by_most_recent']);
        $this->components->twoColumnDetail('<fg=yellow>'.__('tasks.console.schedule_items_dropped').'</>', (string) $counts['baseline_items_dropped']);

        $this->newLine();
        $this->line(__('tasks.console.schedule_deleted', ['ids' => $this->ids($counts['dependencies_deleted'])]));
        $this->line(__('tasks.console.schedule_unresolved', ['ids' => $this->ids($counts['dependencies_unresolved'])]));
        $this->line(__('tasks.console.schedule_baselines_unmapped', ['ids' => $this->ids($counts['baselines_unmapped'])]));

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
