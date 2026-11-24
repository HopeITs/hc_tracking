<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NewUnique = 'activity_schedules_result_location_month_unique';

    private const OldUnique = 'activity_schedules_result_project_month_unique';

    /**
     * Y-15 slice 1, step 3 (D-93, contracts.md §11.2): the override is keyed
     * on the location row. Unique (`result_id`, `proposal_location_id`,
     * `month`) replaces (`result_id`, `project_id`, `month`).
     *
     * Refuses before any DDL while an override (a row with a `project_id`) has
     * no location row: the backfill could not map it (D-80) and it needs a
     * human first. The data refusal comes before the "already applied" check
     * so a test can reach it without DDL. The new unique is created before the
     * old one is dropped so the `result_id` foreign key always keeps an index.
     */
    public function up(): void
    {
        $unmapped = DB::table('activity_schedules')
            ->whereNotNull('project_id')
            ->whereNull('proposal_location_id')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($unmapped !== []) {
            throw new RuntimeException(
                'Calendar overrides no location row carries (activity_schedules ids): '.implode(', ', $unmapped)
                .'. Run php artisan activity-schedules:row-backfill --dry-run; nothing was changed.'
            );
        }

        if (Schema::hasIndex('activity_schedules', self::NewUnique)) {
            return;
        }

        Schema::table('activity_schedules', function (Blueprint $table) {
            $table->unique(['result_id', 'proposal_location_id', 'month'], self::NewUnique);
        });

        Schema::table('activity_schedules', function (Blueprint $table) {
            $table->dropUnique(self::OldUnique);
        });
    }

    /**
     * Put the project-keyed unique back. Every override still carries its
     * `project_id` (written until Y-18), so the old index holds.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('activity_schedules', self::OldUnique)) {
            Schema::table('activity_schedules', function (Blueprint $table) {
                $table->unique(['result_id', 'project_id', 'month'], self::OldUnique);
            });
        }

        if (Schema::hasIndex('activity_schedules', self::NewUnique)) {
            Schema::table('activity_schedules', function (Blueprint $table) {
                $table->dropUnique(self::NewUnique);
            });
        }
    }
};
