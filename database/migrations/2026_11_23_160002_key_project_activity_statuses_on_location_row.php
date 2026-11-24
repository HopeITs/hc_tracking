<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NewUnique = 'project_activity_statuses_location_result_unique';

    private const OldUnique = 'project_activity_statuses_project_result_unique';

    private const ProjectIndex = 'project_activity_statuses_project_index';

    private const LocationIndex = 'project_activity_statuses_location_index';

    /**
     * Y-15 slice 2, step 3 (D-93, contracts.md §13): one status per
     * (`proposal_location_id`, `result_id`). The location becomes required,
     * the new unique replaces (`project_id`, `result_id`).
     *
     * Refuses before any DDL while a status has no row: the backfill could
     * not map it (D-80) and it needs a human first. The data refusal comes
     * before the "already applied" check so a test can reach it without DDL.
     * The `project_id` foreign key used the old unique as its index, so it
     * gets a plain index of its own before that unique is dropped. Each step
     * checks before it acts, so a run stopped half-way can be run again.
     */
    public function up(): void
    {
        $unmapped = DB::table('project_activity_statuses')
            ->whereNull('proposal_location_id')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($unmapped !== []) {
            throw new RuntimeException(
                'Activity statuses no location row carries (project_activity_statuses ids): '.implode(', ', $unmapped)
                .'. Run php artisan activity-statuses:row-backfill --dry-run; nothing was changed.'
            );
        }

        $this->ensureIndex(self::ProjectIndex, fn (Blueprint $table) => $table->index('project_id', self::ProjectIndex));
        $this->ensureIndex(self::NewUnique, fn (Blueprint $table) => $table->unique(['proposal_location_id', 'result_id'], self::NewUnique));
        $this->dropIndexIfPresent(self::OldUnique);
        $this->dropIndexIfPresent(self::LocationIndex);

        Schema::table('project_activity_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_location_id')->nullable(false)->change();
        });
    }

    /**
     * The location becomes optional again and the project-keyed unique comes
     * back. Two statuses of one project and activity (two rows of different
     * proposals carrying the same project) cannot share that unique, so
     * down() stops before DDL and says which.
     */
    public function down(): void
    {
        $clashes = DB::table('project_activity_statuses')
            ->whereNotNull('project_id')
            ->select('project_id', 'result_id')
            ->groupBy('project_id', 'result_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($clashes->isNotEmpty()) {
            throw new RuntimeException('Two statuses share a project and activity: '.$clashes->map(fn (object $row): string => "{$row->project_id}/{$row->result_id}")->implode(', ').'. Nothing was changed.');
        }

        Schema::table('project_activity_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_location_id')->nullable()->change();
        });

        $this->ensureIndex(self::OldUnique, fn (Blueprint $table) => $table->unique(['project_id', 'result_id'], self::OldUnique));
        // The location foreign key leans on the new unique once it exists: give it its own index first.
        $this->ensureIndex(self::LocationIndex, fn (Blueprint $table) => $table->index('proposal_location_id', self::LocationIndex));
        $this->dropIndexIfPresent(self::NewUnique);
        $this->dropIndexIfPresent(self::ProjectIndex);
    }

    /**
     * @param  Closure(Blueprint): mixed  $add
     */
    private function ensureIndex(string $name, Closure $add): void
    {
        if (! Schema::hasIndex('project_activity_statuses', $name)) {
            Schema::table('project_activity_statuses', fn (Blueprint $table) => $add($table));
        }
    }

    private function dropIndexIfPresent(string $name): void
    {
        if (Schema::hasIndex('project_activity_statuses', $name)) {
            Schema::table('project_activity_statuses', fn (Blueprint $table) => $table->dropIndex($name));
        }
    }
};
