<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\ProjectsRetireAudit as Audit;
use Illuminate\Console\Command;

/**
 * Y-18 (D-92): print what is still attached to a project, row by row. The
 * gate in front of the drop — Dev 1 runs it on production after the backup
 * and before the migration, and it must read zero everywhere.
 *
 * It exits **non-zero while anything is left**, so a deploy script can stop
 * on it. Reads only: nothing here writes, locks or deletes. A row above zero
 * is reported and left alone (D-80); what to do with it is Dev 1's ruling,
 * never this command's guess.
 *
 * @deprecated Y-18 slice 3 deletes this command with the `projects` table.
 */
class ProjectsRetireAudit extends Command
{
    /**
     * @var string
     */
    protected $signature = 'projects:retire-audit';

    /**
     * @var string
     */
    protected $description = 'Count what is still attached to a project before the table is dropped (Y-18)';

    public function handle(Audit $audit): int
    {
        $result = $audit->run();

        $this->section(__('projects.console.retire_audit_columns'), $result['columns']);
        $this->section(__('projects.console.retire_audit_morphs'), $result['morphs']);
        $this->section(__('projects.console.retire_audit_orphans'), ['projects' => $result['projects_without_row']]);

        $this->newLine();

        if ($audit->isClean($result)) {
            $this->components->info(__('projects.console.retire_audit_clean'));

            return self::SUCCESS;
        }

        $this->components->error(__('projects.console.retire_audit_dirty', ['count' => $result['total']]));
        $this->line(__('projects.console.retire_audit_hint'));

        return self::FAILURE;
    }

    /**
     * One block of counts. The keys are table and column names, so they are
     * printed as they are: an identifier is not a translated string.
     *
     * @param  array<string, int>  $counts
     */
    private function section(string $title, array $counts): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>'.$title.'</>');

        foreach ($counts as $name => $count) {
            $this->components->twoColumnDetail(
                $name,
                $count === 0 ? '<fg=green>0</>' : '<fg=red>'.$count.'</>',
            );
        }
    }
}
