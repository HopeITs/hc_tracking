<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\StoredSubjectBackfill;
use Illuminate\Console\Command;

/**
 * Re-point every stored notification, activity and alert ledger entry whose
 * subject is a project at the location row that took that project's place
 * (Y-16, D-93, D-94), and with --dry-run only report it. The dry run does the
 * same work inside a rolled-back transaction, so it prints the exact counts a
 * real run would write (wave4/AGENT.md §4).
 *
 * The rule lives in `StoredSubjectBackfill`, not here. Safe to re-run — only a
 * record still naming a project is written. A project no row carries is listed
 * with how many records it holds and left alone (D-80); `SubjectLinks` keeps
 * answering for those through `ProjectSuccessor`.
 *
 * @deprecated Y-18 freezes what is left in `project_redirects`.
 */
class BackfillStoredSubjects extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:subject-backfill {--dry-run : Only report; write nothing}';

    /**
     * @var string
     */
    protected $description = 'Point every stored project subject at the location row that carries it (Y-16)';

    public function handle(StoredSubjectBackfill $backfill): int
    {
        $apply = ! $this->option('dry-run');

        $this->components->info($apply
            ? __('notifications.console.subject_backfill_applying')
            : __('notifications.console.subject_backfill_dry_run'));

        $counts = $backfill->run($apply);

        $this->newLine();

        foreach ($counts['moved'] as $table => $moved) {
            $this->components->twoColumnDetail($table, (string) $moved);
        }

        $this->components->twoColumnDetail(
            '<fg=green>'.__('notifications.console.subject_backfill_moved').'</>',
            (string) array_sum($counts['moved']),
        );

        $this->newLine();
        $this->line(__('notifications.console.subject_backfill_rows', ['rows' => $this->pairs($counts['rows'])]));
        $this->line(__('notifications.console.subject_backfill_unmapped', ['projects' => $this->pairs($counts['unmapped'])]));

        return self::SUCCESS;
    }

    /**
     * `id × count`, in id order.
     *
     * @param  array<int, int>  $counts
     */
    private function pairs(array $counts): string
    {
        if ($counts === []) {
            return __('notifications.console.subject_backfill_none');
        }

        ksort($counts);

        return implode(', ', array_map(
            fn (int $count, int $id): string => "{$id}×{$count}",
            $counts,
            array_keys($counts),
        ));
    }
}
