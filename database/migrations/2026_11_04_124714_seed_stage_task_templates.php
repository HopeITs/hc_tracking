<?php

declare(strict_types=1);

use App\Support\DefaultStageTaskTemplates;
use Illuminate\Database\Migrations\Migration;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Put the four starting stage task templates (D-63) into an existing
 * database, the way the seeder does it for a fresh one. Idempotent: the rows
 * are upserted on their unique key, so a re-run changes nothing and a
 * template the PR Manager has since switched off stays off.
 *
 * A department HR does not have is reported and skipped, never guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = DefaultStageTaskTemplates::ensure();

        $this->report("Stage task templates: {$result['written']} written.");

        foreach ($result['skipped'] as $skipped) {
            $this->report("  skipped (no such HR department): {$skipped}");
        }
    }

    /**
     * Deliberately a no-op: the templates are a list people edit, so dropping
     * what they changed on a rollback would lose their work. The table itself
     * goes with the migration that created it.
     */
    public function down(): void {}

    private function report(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("  {$message}");
        }
    }
};
