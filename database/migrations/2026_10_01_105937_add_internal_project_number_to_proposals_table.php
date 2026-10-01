<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every proposal carries the internal project number the team tracks it
     * by: required and unique. The collation (utf8mb4_unicode_ci) makes the
     * unique index case-insensitive, so "HC-1" and "hc-1" collide on purpose.
     *
     * Existing rows get a unique "TEMP-0001" style placeholder for the team to
     * replace. The id is padded in PHP: str_pad never shortens a longer id,
     * where SQL LPAD(id, 4, '0') would cut 12345 to 1234 and could collide.
     * The rows are written through the query builder, so the backfill records
     * no activity and notifies nobody.
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('internal_project_number', 50)->nullable()->after('name');
        });

        DB::table('proposals')->select('id')->lazyById()->each(function (object $proposal): void {
            DB::table('proposals')->where('id', $proposal->id)->update([
                'internal_project_number' => 'TEMP-'.str_pad((string) $proposal->id, 4, '0', STR_PAD_LEFT),
            ]);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->string('internal_project_number', 50)->change();
            $table->unique('internal_project_number');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropUnique(['internal_project_number']);
            $table->dropColumn('internal_project_number');
        });
    }
};
