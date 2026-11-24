<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per table: the new unique (on the location row), the old one (on the
     * project), and the columns of each.
     *
     * @var array<string, array{new: string, newColumns: list<string>, old: string, oldColumns: list<string>}>
     */
    private const Uniques = [
        'plan_updates' => [
            'new' => 'plan_updates_plan_location_month_unique',
            'newColumns' => ['activity_plan_id', 'proposal_location_id', 'month'],
            'old' => 'plan_updates_plan_project_month_unique',
            'oldColumns' => ['activity_plan_id', 'project_id', 'month'],
        ],
        'activity_plan_follow_up_tasks' => [
            'new' => 'apfut_follow_up_location_unique',
            'newColumns' => ['activity_plan_follow_up_id', 'proposal_location_id'],
            'old' => 'activity_plan_follow_up_tasks_follow_up_project_unique',
            'oldColumns' => ['activity_plan_follow_up_id', 'project_id'],
        ],
    ];

    /**
     * Y-15 slice 3, step 3 (D-93): one update per plan, location row and
     * month; one follow-up task per follow-up and row. The old project-keyed
     * uniques are dropped.
     *
     * Refuses before any DDL while a project-keyed row has no location: the
     * backfill could not map it (D-80) and it needs a human first. The data
     * refusal comes before the "already applied" checks so a test can reach
     * it without DDL. Each new unique leads with the same column as the old
     * one, so it is created first and the plan / follow-up foreign key always
     * keeps an index. Each step checks before it acts, so a run stopped
     * half-way can be run again.
     */
    public function up(): void
    {
        $unmapped = [];

        foreach (array_keys(self::Uniques) as $table) {
            $ids = DB::table($table)->whereNotNull('project_id')->whereNull('proposal_location_id')->orderBy('id')->pluck('id')->all();

            if ($ids !== []) {
                $unmapped[] = "{$table}: ".implode(', ', $ids);
            }
        }

        if ($unmapped !== []) {
            throw new RuntimeException(
                'Plan update rows no location row carries ('.implode('; ', $unmapped).')'
                .'. Run php artisan plan-updates:row-backfill --dry-run; nothing was changed.'
            );
        }

        foreach (self::Uniques as $table => $unique) {
            $this->ensureUnique($table, $unique['new'], $unique['newColumns']);
            $this->dropIndexIfPresent($table, $unique['old']);
        }
    }

    /**
     * The project-keyed uniques come back. Two rows of one plan, project and
     * month (two location rows carrying the same project) cannot share that
     * unique, so down() stops before DDL and says which.
     */
    public function down(): void
    {
        foreach (self::Uniques as $table => $unique) {
            $clashes = DB::table($table)
                ->whereNotNull('project_id')
                ->select($unique['oldColumns'])
                ->groupBy($unique['oldColumns'])
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($clashes > 0) {
                throw new RuntimeException("{$table}: {$clashes} groups share the project-keyed unique; nothing was changed.");
            }
        }

        foreach (self::Uniques as $table => $unique) {
            $this->ensureUnique($table, $unique['old'], $unique['oldColumns']);
            $this->dropIndexIfPresent($table, $unique['new']);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUnique(string $table, string $name, array $columns): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }
};
