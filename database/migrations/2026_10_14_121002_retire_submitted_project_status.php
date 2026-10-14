<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Retire the duplicated `submitted` project status and settle legacy
     * `approved` rows (contracts.md §3). Before T05 both were set by hand.
     *
     * - legacy `submitted` and `approved`, dated → `active`;
     * - legacy `submitted` and `approved`, undated → `draft`.
     *
     * "Dated" means both `start_date` and `end_date` are set. Drafts that
     * already hold dates are only reported (ids), never changed: T27 lists
     * them. Query builder only, so no model event or activity fires.
     */
    public function up(): void
    {
        $before = $this->counts();

        DB::transaction(function (): void {
            $legacy = fn () => DB::table('projects')->whereIn('status', ['submitted', 'approved']);

            $legacy()->whereNotNull('start_date')->whereNotNull('end_date')->update(['status' => 'active', 'updated_at' => now()]);
            $legacy()->update(['status' => 'draft', 'updated_at' => now()]);
        });

        $after = $this->counts();
        $drafts = DB::table('projects')
            ->where('status', 'draft')
            ->where(fn ($query) => $query->whereNotNull('start_date')->orWhereNotNull('end_date'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->report('Project statuses before: '.$this->format($before).'; after: '.$this->format($after).'.');
        $this->report('Drafts that hold dates (reported, not changed; for T27): '.($drafts === [] ? 'none' : '#'.implode(', #', $drafts)).'.');
    }

    /**
     * Deliberately a no-op: which projects were `submitted` or `approved`
     * is not recoverable, and `submitted` is retired.
     */
    public function down(): void {}

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return DB::table('projects')->selectRaw('status, COUNT(*) as total')->groupBy('status')->orderBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total)->all();
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function format(array $counts): string
    {
        return $counts === [] ? 'none' : implode(', ', array_map(fn (string $status, int $total): string => "{$status} {$total}", array_keys($counts), $counts));
    }

    private function report(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("  {$message}");
        }
    }
};
