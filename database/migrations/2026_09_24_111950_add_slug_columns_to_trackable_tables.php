<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a `slug` column to every table whose show route now binds by slug
 * instead of id (see App\Concerns\HasSlug).
 *
 * Stays nullable even after backfilling existing rows: several tests (e.g.
 * DonorTest) insert rows straight through the query builder to exercise the
 * table's own schema, bypassing HasSlug::save() entirely, and a NOT NULL
 * slug would break that legitimate pattern app-wide. A row without a slug
 * simply has no working show route until one is generated. MySQL's UNIQUE
 * index allows any number of NULLs, so this loses no collision protection.
 */
return new class extends Migration
{
    /**
     * Tables with a plain text column to slug directly.
     *
     * @var array<string, string>
     */
    private const SIMPLE_TABLES = [
        'strategies' => 'name',
        'projects' => 'name',
        'milestones' => 'name',
        'tasks' => 'title',
        'proposals' => 'name',
        'donors' => 'name',
    ];

    public function up(): void
    {
        foreach (array_keys(self::SIMPLE_TABLES) as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('slug')->nullable()->after('id');
            });
        }

        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('id');
        });

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('id');
        });

        foreach (self::SIMPLE_TABLES as $table => $sourceColumn) {
            $this->backfill($table, fn (stdClass $row): string => (string) $row->{$sourceColumn});
        }

        $this->backfill('donor_proposal', function (stdClass $row): string {
            $donor = DB::table('donors')->where('id', $row->donor_id)->value('slug');
            $proposal = DB::table('proposals')->where('id', $row->proposal_id)->value('slug');

            return trim(($donor ?? '').'-'.($proposal ?? ''), '-');
        });

        $this->backfill('reports', function (stdClass $row): string {
            $indicatorName = DB::table('plan_indicators')
                ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
                ->where('plan_indicators.id', $row->plan_indicator_id)
                ->value('indicators.name');

            return trim(($indicatorName ?? 'report').'-'.$row->planned_date);
        });

        $this->backfill('annual_plans', function (stdClass $row): string {
            $programSlug = DB::connection('hr')->table('programs')->where('id', $row->program_id)->value('slug');

            return trim(($programSlug ?? 'program').'-'.$row->year);
        });

        foreach ([...array_keys(self::SIMPLE_TABLES), 'donor_proposal', 'reports', 'annual_plans'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('slug')->nullable()->unique()->change();
            });
        }
    }

    public function down(): void
    {
        foreach ([...array_keys(self::SIMPLE_TABLES), 'donor_proposal', 'reports', 'annual_plans'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }

    /**
     * Fill every row's slug from $source, keeping slugs unique within the
     * table by appending -2, -3, ... on collision.
     *
     * @param  callable(stdClass): string  $source
     */
    private function backfill(string $table, callable $source): void
    {
        $seen = [];

        DB::table($table)->orderBy('id')->select(['id'])->each(function (object $idRow) use ($table, $source, &$seen): void {
            $row = DB::table($table)->where('id', $idRow->id)->first();
            $base = Str::slug($source($row));
            $base = $base === '' ? 'row-'.$idRow->id : $base;

            $slug = $base;
            $suffix = 2;

            while (isset($seen[$slug])) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            $seen[$slug] = true;

            DB::table($table)->where('id', $idRow->id)->update(['slug' => $slug]);
        });
    }
};
