<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ProgramImport;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Seeds the tracking-owned `programs` columns from HR once, and afterwards
 * reports the drift between the two (D-68, W-34b).
 *
 * All the work is in `ProgramImport`, which the seeders share; this is its
 * command line. `--dry-run` — the default — writes nothing and doubles as the
 * drift report.
 */
class ImportProgramsFromHr extends Command
{
    /**
     * @var string
     */
    protected $signature = 'programs:import-from-hr
        {--dry-run : Only report; the default when --apply is not given}
        {--apply : Write the names, codes, slugs and active flags}
        {--force : Also overwrite a name that was edited in tracking}';

    /**
     * @var string
     */
    protected $description = 'Seed the tracking-owned program columns from HR, or report the drift between the two';

    public function handle(ProgramImport $import): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->report($import->plan($force));

        if (! $apply) {
            $this->line('');
            $this->info('Dry run: nothing was written. Re-run with --apply.');

            return self::SUCCESS;
        }

        $result = $import->apply($force);

        $this->line('');

        if ($result['idFloor'] !== null) {
            $this->line(sprintf('Next program id set to %d.', $result['idFloor']));
        }

        $this->info(sprintf('Done: %d inserted, %d updated.', $result['inserted'], $result['updated']));

        return self::SUCCESS;
    }

    /**
     * @param  array{inserts: Collection<int, stdClass>, updates: Collection<int, non-empty-array{name_en?: string, code?: string, slug?: string, is_active?: bool}>, orphans: Collection<int, stdClass>, kept: Collection<int, array{id: int, ours: string, theirs: string}>}  $plan
     */
    private function report(array $plan): void
    {
        $this->components->twoColumnDetail('<fg=gray>Programs in HR, not in tracking</>', (string) $plan['inserts']->count());

        foreach ($plan['inserts'] as $program) {
            $this->line(sprintf('  + %d %s', $program->id, trim((string) $program->name)));
        }

        $this->components->twoColumnDetail('<fg=gray>Tracking rows to fill from HR</>', (string) $plan['updates']->count());

        foreach ($plan['updates'] as $id => $changes) {
            $this->line(sprintf('  ~ %d %s', $id, collect($changes)->map(fn (mixed $value, string $column): string => $column.' = '.(is_bool($value) ? var_export($value, true) : (string) $value))->implode(', ')));
        }

        $this->components->twoColumnDetail('<fg=gray>Tracking programs HR no longer has</>', (string) $plan['orphans']->count());

        foreach ($plan['orphans'] as $program) {
            $this->line(sprintf('  ! %d %s (kept; projects and plans hang off it)', $program->id, $program->name_en));
        }

        if ($plan['kept']->isNotEmpty()) {
            $this->components->twoColumnDetail('<fg=gray>Names kept in tracking (--force to overwrite)</>', (string) $plan['kept']->count());

            foreach ($plan['kept'] as $row) {
                $this->line(sprintf('  = %d: «%s» in tracking, «%s» in HR', $row['id'], $row['ours'], $row['theirs']));
            }
        }
    }
}
