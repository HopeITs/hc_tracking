<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programs become a tracking-owned table (D-68, W-34b).
 *
 * Until now a `programs` row carried only an id mirroring `hchr_programs`,
 * a strategy, a manager and a donor-attribution setting; the name, code,
 * slug and active flag were read live out of HR, and the read-only `hr`
 * connection meant tracking could not create a program at all. The columns
 * come home here.
 *
 * Nothing is backfilled in this migration: the HR connection is unavailable
 * while the test suite migrates, and reading it here would make the migration
 * unrunnable. `programs:import-from-hr --apply` fills the columns and must run
 * straight after this migration — until it does, names are empty and slugs are
 * null, so the program show routes have no key to resolve.
 *
 * `name_en` and `code` are NOT NULL with an empty default so the many places
 * that insert a program row through the query builder keep working.
 */
return new class extends Migration
{
    /**
     * The columns this migration owns, in the order they are added.
     *
     * @var array<string, string>
     */
    private const Columns = [
        'name_en' => 'id',
        'name_ar' => 'name_en',
        'code' => 'name_ar',
        'slug' => 'code',
        'is_active' => 'slug',
    ];

    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            if (! Schema::hasColumn('programs', 'name_en')) {
                $table->string('name_en')->default('')->after(self::Columns['name_en']);
            }

            if (! Schema::hasColumn('programs', 'name_ar')) {
                $table->string('name_ar')->nullable()->after(self::Columns['name_ar']);
            }

            if (! Schema::hasColumn('programs', 'code')) {
                $table->string('code', 50)->default('')->after(self::Columns['code']);
            }

            if (! Schema::hasColumn('programs', 'slug')) {
                $table->string('slug')->nullable()->after(self::Columns['slug']);
                $table->unique('slug', 'programs_slug_unique');
            }

            if (! Schema::hasColumn('programs', 'is_active')) {
                $table->boolean('is_active')->default(true)->after(self::Columns['is_active']);
                $table->index('is_active', 'programs_is_active_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            if (Schema::hasColumn('programs', 'is_active')) {
                $table->dropIndex('programs_is_active_index');
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('programs', 'slug')) {
                $table->dropUnique('programs_slug_unique');
                $table->dropColumn('slug');
            }

            foreach (['code', 'name_ar', 'name_en'] as $column) {
                if (Schema::hasColumn('programs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
