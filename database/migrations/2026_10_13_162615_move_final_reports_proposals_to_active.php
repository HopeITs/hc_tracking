<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Retire the `final reports` status (D-02): its proposals move back to
     * `active`, each with one status note saying why. The old notes that say
     * `final reports` stay as history.
     *
     * Query builder only, so no model event, activity or listener fires.
     */
    public function up(): void
    {
        $before = DB::table('proposals')->where('status', 'final reports')->count();

        DB::transaction(function (): void {
            $ids = DB::table('proposals')->where('status', 'final reports')->lockForUpdate()->orderBy('id')->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            $now = now();
            $context = __('proposals.final_reports_status_retired');

            DB::table('proposals')->whereIn('id', $ids)->update(['status' => 'active', 'updated_at' => $now]);

            DB::table('proposal_status_notes')->insert($ids->map(fn (int $id): array => [
                'proposal_id' => $id,
                'status' => 'active',
                'context' => $context,
                'date' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        $after = DB::table('proposals')->where('status', 'final reports')->count();

        $this->report("Final reports retired: {$before} proposal(s) in `final reports` before, {$after} after; ".($before - $after).' moved to `active`.');
    }

    /**
     * Deliberately a no-op: which proposals were in `final reports` is still
     * readable from their status notes, but the status itself is retired and
     * must not come back.
     */
    public function down(): void {}

    private function report(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("  {$message}");
        }
    }
};
