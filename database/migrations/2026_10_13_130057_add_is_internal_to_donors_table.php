<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-funded proposals point at one internal donor. It is created here as
     * well as in the seeder so production gets it without a seeder run.
     *
     * The test suite migrates a fresh database for every test and asserts on
     * donor lists and counts, so it skips the insert and seeds through
     * CoreDataSeeder instead, which holds the same idempotent rule.
     */
    public function up(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false);
        });

        if (! app()->runningUnitTests() && ! DB::table('donors')->where('is_internal', true)->exists()) {
            DB::table('donors')->insert([
                'name' => 'تمويل ذاتي — Hope',
                'slug' => 'self-funding-hope',
                'is_internal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('donors')->where('is_internal', true)->whereNotExists(
            fn ($query) => $query->select(DB::raw(1))->from('proposals')->whereColumn('proposals.donor_id', 'donors.id')
        )->delete();

        Schema::table('donors', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
