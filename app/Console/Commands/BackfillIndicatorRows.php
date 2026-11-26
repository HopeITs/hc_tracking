<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\IndicatorRowBackfill;
use Illuminate\Console\Command;

/**
 * Move every indicator instance scoped to a project onto its location row
 * (Y-16 slice 1, D-93), and with --dry-run only report it. The dry run does
 * the same work inside a rolled-back transaction, so it prints the exact
 * counts a real run would write (wave4/AGENT.md §4).
 *
 * The twin of the `backfill_indicators_proposal_location_id` migration: both
 * go through `IndicatorRowBackfill`, so the rule lives in one place. Safe to
 * re-run. Instances it cannot map are listed by id and left alone (D-80).
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillIndicatorRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'indicators:row-backfill {--dry-run : Only report; write nothing}';

    /**
     * @var string
     */
    protected $description = 'Give every project-scoped indicator instance its location row (Y-16)';

    public function handle(IndicatorRowBackfill $backfill): int
    {
        $apply = ! $this->option('dry-run');

        $this->components->info($apply ? __('indicators.console.row_backfill_applying') : __('indicators.console.row_backfill_dry_run'));

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(__('indicators.console.row_backfill_instances'), $counts['moved'].' / '.$counts['seen']);
        $this->components->twoColumnDetail(__('indicators.console.row_backfill_by_own_proposal'), (string) $counts['by_own_proposal']);
        $this->components->twoColumnDetail(__('indicators.console.row_backfill_by_date'), (string) $counts['by_date']);
        $this->components->twoColumnDetail(__('indicators.console.row_backfill_by_most_recent'), (string) $counts['by_most_recent']);

        $this->newLine();
        $this->line(__('indicators.console.row_backfill_rows', ['rows' => $this->rows($counts['rows'])]));
        $this->line(__('indicators.console.row_backfill_unmapped', ['ids' => $this->ids($counts['unmapped'])]));

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
            return __('indicators.console.row_backfill_none');
        }

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
        return $ids === [] ? __('indicators.console.row_backfill_none') : implode(', ', $ids);
    }
}
