<?php

declare(strict_types=1);

use App\Support\DefaultNarrativeQuestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The starting narrative question bank, created here as well as in the
 * seeder so production gets it without a seeder run.
 * DefaultNarrativeQuestions::ensure() is the one idempotent rule both use.
 *
 * The test suite migrates a fresh database for every test and asserts on
 * question lists and counts, so it skips the insert (the same rule as the
 * default sectors).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            DefaultNarrativeQuestions::ensure();
        }
    }

    /**
     * Removes only the questions that still carry a default text.
     */
    public function down(): void
    {
        DB::table('narrative_questions')
            ->whereIn('text_ar', array_column(DefaultNarrativeQuestions::all(), 'text_ar'))
            ->delete();
    }
};
