<?php

declare(strict_types=1);

use App\Support\Locations\LocationBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-11, step 2 (D-92, D-93): tighten what step 1 added. The rows are
     * filled first, then `slug` gets its unique index — never one migration
     * that adds, fills and constrains (wave4/AGENT.md §4).
     *
     * It refuses, before any DDL, while a project exists that no row carries:
     * that project needs a proposal first (D-84; an internal-donor one when
     * self-funded, D-87), which is a human step and never a row a migration
     * invents (D-80). Run `locations:backfill-from-projects --dry-run` to see
     * the ids.
     */
    public function up(): void
    {
        // The data refusals come before the "already applied" check on
        // purpose: they say what a human has to fix, and they are reachable
        // in a test without any DDL (MySQL commits implicitly on DDL, which
        // would break RefreshDatabase's transaction).
        $rowless = app(LocationBackfill::class)->run(apply: false)['projects_without_row'];

        if ($rowless !== []) {
            throw new RuntimeException(
                'Projects no location row carries (ids): '.implode(', ', $rowless)
                .'. Each needs a proposal first (D-84, D-87); nothing was changed.'
            );
        }

        if ($this->hasUniqueSlug()) {
            throw new RuntimeException('proposal_locations.slug is already unique; nothing was changed.');
        }

        $duplicates = DB::table('proposal_locations')
            ->whereNotNull('slug')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('count(*) > 1')
            ->pluck('slug')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException('Location rows sharing a slug: '.implode(', ', $duplicates).'; nothing was changed.');
        }

        Schema::table('proposal_locations', function (Blueprint $table) {
            $table->unique('slug', 'proposal_locations_slug_unique');
        });
    }

    public function down(): void
    {
        if (! $this->hasUniqueSlug()) {
            return;
        }

        Schema::table('proposal_locations', function (Blueprint $table) {
            $table->dropUnique('proposal_locations_slug_unique');
        });
    }

    /**
     * Read from the schema rather than guessed, so `down()` works on a
     * database that already holds backfilled rows.
     */
    private function hasUniqueSlug(): bool
    {
        foreach (Schema::getIndexes('proposal_locations') as $index) {
            if ($index['name'] === 'proposal_locations_slug_unique') {
                return true;
            }
        }

        return false;
    }
};
