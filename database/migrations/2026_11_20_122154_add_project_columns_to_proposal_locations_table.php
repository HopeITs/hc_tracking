<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-11, step 1 (D-92, D-93): the location row takes the project's place,
     * so it carries what the project carries — status, period, calendar,
     * description and the five beneficiary targets.
     *
     * Additive only (wave4/AGENT.md §4): every column is nullable or
     * defaulted and nothing is backfilled here. `locations:backfill-from-projects`
     * fills the rows; a later migration adds the unique index on `slug`.
     * Pre-checks run before any DDL, since MySQL does not roll DDL back.
     *
     * `status` holds ProjectStatus values and `slug` has no index yet: a row
     * has no slug until the backfill gives it one.
     */
    public function up(): void
    {
        $existing = array_values(array_filter(
            ['slug', 'status', 'start_date', 'end_date', 'schedule_weekend', 'working_days', 'description', 'target_beneficiaries', 'target_men', 'target_women', 'target_boys', 'target_girls'],
            fn (string $column): bool => Schema::hasColumn('proposal_locations', $column),
        ));

        if ($existing !== []) {
            throw new RuntimeException('proposal_locations already has: '.implode(', ', $existing).'; nothing was changed.');
        }

        Schema::table('proposal_locations', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('sort');
            $table->string('status', 50)->default('draft')->after('slug');
            $table->date('start_date')->nullable()->after('status');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('schedule_weekend', 10)->default('sat-sun')->after('end_date');
            $table->json('working_days')->nullable()->after('schedule_weekend');
            $table->text('description')->nullable()->after('working_days');
            $table->unsignedInteger('target_beneficiaries')->nullable()->after('description');
            $table->unsignedInteger('target_men')->nullable()->after('target_beneficiaries');
            $table->unsignedInteger('target_women')->nullable()->after('target_men');
            $table->unsignedInteger('target_boys')->nullable()->after('target_women');
            $table->unsignedInteger('target_girls')->nullable()->after('target_boys');

            $table->index('status', 'proposal_locations_status_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('proposal_locations', 'status')) {
            return;
        }

        Schema::table('proposal_locations', function (Blueprint $table) {
            $table->dropIndex('proposal_locations_status_index');
            $table->dropColumn([
                'slug',
                'status',
                'start_date',
                'end_date',
                'schedule_weekend',
                'working_days',
                'description',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
            ]);
        });
    }
};
