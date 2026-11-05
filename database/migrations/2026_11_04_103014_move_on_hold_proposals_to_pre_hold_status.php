<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Retire the `on hold` status (D-58, W-06): every proposal holding it goes
 * back to the status it was held from and carries the hold as
 * `on_hold_since` instead. `status_remind_on` is left exactly as it is, so
 * the reminder keeps firing on the date someone already chose.
 *
 * The pre-hold status is the last status note before the hold note. A row
 * whose trail does not show one falls back to `submitted` and is named in
 * the printed report, since that is the only case a person may need to look
 * at by hand.
 *
 * Query builder only, so no model event, activity or listener fires.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = DB::table('proposals')->where('status', 'on hold')->count();
        $guessed = [];

        DB::transaction(function () use (&$guessed): void {
            $ids = DB::table('proposals')->where('status', 'on hold')->lockForUpdate()->orderBy('id')->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            $now = now();
            $context = __('proposals.on_hold_status_retired');
            $notes = [];

            foreach ($ids as $id) {
                $holdNote = DB::table('proposal_status_notes')
                    ->where('proposal_id', $id)
                    ->where('status', 'on hold')
                    ->orderByDesc('id')
                    ->first(['id', 'date']);

                $preHold = $holdNote === null ? null : DB::table('proposal_status_notes')
                    ->where('proposal_id', $id)
                    ->where('id', '<', $holdNote->id)
                    ->where('status', '!=', 'on hold')
                    ->orderByDesc('id')
                    ->value('status');

                if ($preHold === null) {
                    $guessed[] = $id;
                    $preHold = 'submitted';
                }

                DB::table('proposals')->where('id', $id)->update([
                    'status' => $preHold,
                    'on_hold_since' => $holdNote->date ?? $now->toDateString(),
                    'updated_at' => $now,
                ]);

                $notes[] = [
                    'proposal_id' => $id,
                    'status' => $preHold,
                    'kind' => 'off_hold',
                    'context' => $context,
                    'date' => $now->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('proposal_status_notes')->insert($notes);
        });

        $after = DB::table('proposals')->where('status', 'on hold')->count();
        $flagged = DB::table('proposals')->whereNotNull('on_hold_since')->count();

        $this->report("On hold retired: {$before} proposal(s) in `on hold` before, {$after} after; {$flagged} now carry the flag.");

        if ($guessed !== []) {
            $this->report('  No pre-hold status in the trail, set to `submitted`: #'.implode(', #', $guessed));
        }
    }

    /**
     * Deliberately a no-op: which proposals were in `on hold` is still
     * readable from their status notes and their `on_hold_since`, but the
     * status itself is retired and must not come back. Reversing this is
     * manual.
     */
    public function down(): void
    {
        $this->report('`on hold` is retired (D-58): reversing this migration is manual.');
    }

    private function report(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("  {$message}");
        }
    }
};
