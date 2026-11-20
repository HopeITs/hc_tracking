<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\RiskRowBackfill;
use Illuminate\Console\Command;

/**
 * Move every register risk onto the location row that took its project's
 * place (Y-13, D-93, D-94), and with --dry-run only report it. The dry run
 * does the same work inside a rolled-back transaction, so it prints the exact
 * counts a real run would write (wave4/AGENT.md §4).
 *
 * The twin of the `backfill_risks_proposal_location_id` migration: both go
 * through `RiskRowBackfill`, so the rule lives in one place. Safe to re-run —
 * only a risk with no row yet is written. Risks it cannot map are listed by id
 * and left alone (D-80).
 *
 * @deprecated Y-18 deletes this command with the `projects` table.
 */
class BackfillRiskRows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'risks:row-backfill {--dry-run : Only report; write nothing}';

    /**
     * @var string
     */
    protected $description = 'Give every register risk the location row that carries its project (Y-13)';

    public function handle(RiskRowBackfill $backfill): int
    {
        $apply = ! $this->option('dry-run');

        $this->components->info($apply ? __('risks.console.backfill_applying') : __('risks.console.backfill_dry_run'));

        $counts = $backfill->run($apply);

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=green>'.__('risks.console.backfill_moved', ['count' => $counts['risks_moved']]).'</>',
            (string) $counts['risks_moved'],
        );
        $this->components->twoColumnDetail(__('risks.console.backfill_by_date', ['count' => $counts['by_date']]), (string) $counts['by_date']);
        $this->components->twoColumnDetail(__('risks.console.backfill_by_most_recent', ['count' => $counts['by_most_recent']]), (string) $counts['by_most_recent']);
        $this->components->twoColumnDetail(__('risks.console.backfill_by_own_proposal', ['count' => $counts['by_own_proposal']]), (string) $counts['by_own_proposal']);

        $this->newLine();
        $this->line(__('risks.console.backfill_rows', ['rows' => $this->rows($counts['rows'])]));
        $this->line(__('risks.console.backfill_unmapped', ['ids' => $this->ids($counts['unmapped'])]));

        return self::SUCCESS;
    }

    /**
     * The risks each row took, as `row id × count`.
     *
     * @param  array<int, int>  $rows
     */
    private function rows(array $rows): string
    {
        if ($rows === []) {
            return __('risks.console.backfill_none');
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
        return $ids === [] ? __('risks.console.backfill_none') : implode(', ', $ids);
    }
}
