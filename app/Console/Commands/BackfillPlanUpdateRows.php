<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\PlanUpdateRowBackfill;
use Illuminate\Console\Command;

/**
 * Move every monthly plan update and every follow-up task onto the location
 * row of the plan's proposal that carries its project (Y-15 slice 3, D-93),
 * and with --dry-run only report it. The dry run does the same work inside a
 * rolled-back transaction, so it prints the exact counts a real run would
 * write (wave4/AGENT.md §4).
 *
 * The twin of the `backfill_plan_update_tables_proposal_location_id`
 * migration: both go through `PlanUpdateRowBackfill`, so the rule lives in
 * one place. Safe to re-run. Rows it cannot map are listed by id and left
 * alone (D-80).
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillPlanUpdateRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'plan-updates:row-backfill {--dry-run : Only report; write nothing}';

    /**
     * @var string
     */
    protected $description = 'Give every plan update and follow-up task the location row of its plan\'s proposal that carries its project (Y-15)';

    public function handle(PlanUpdateRowBackfill $backfill): int
    {
        $apply = ! $this->option('dry-run');

        $this->components->info($apply ? __('annual_plans.console.row_backfill_applying') : __('annual_plans.console.row_backfill_dry_run'));

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(__('annual_plans.console.row_backfill_updates'), $counts['updates']['moved'].' / '.$counts['updates']['seen']);
        $this->components->twoColumnDetail(__('annual_plans.console.row_backfill_tasks'), $counts['follow_up_tasks']['moved'].' / '.$counts['follow_up_tasks']['seen']);
        $this->components->twoColumnDetail(__('annual_plans.console.row_backfill_by_own_proposal'), (string) $counts['by_own_proposal']);
        $this->components->twoColumnDetail(__('annual_plans.console.row_backfill_by_date'), (string) $counts['by_date']);
        $this->components->twoColumnDetail(__('annual_plans.console.row_backfill_by_most_recent'), (string) $counts['by_most_recent']);

        $this->newLine();
        $this->line(__('annual_plans.console.row_backfill_rows', ['rows' => $this->rows($counts['rows'])]));
        $this->line(__('annual_plans.console.row_backfill_unmapped_updates', ['ids' => $this->ids($counts['updates']['unmapped'])]));
        $this->line(__('annual_plans.console.row_backfill_unmapped_tasks', ['ids' => $this->ids($counts['follow_up_tasks']['unmapped'])]));

        return self::SUCCESS;
    }

    /**
     * What each row took, as `row id × count`.
     *
     * @param  array<int, int>  $rows
     */
    private function rows(array $rows): string
    {
        if ($rows === []) {
            return __('annual_plans.console.row_backfill_none');
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
        return $ids === [] ? __('annual_plans.console.row_backfill_none') : implode(', ', $ids);
    }
}
