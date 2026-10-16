<?php

declare(strict_types=1);

use App\Support\DefaultNarrativeQuestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * Removes only the questions that still carry a default text, and skips
     * the questions a report template copied.
     */
    public function down(): void
    {
        DB::table('narrative_questions')
            ->whereIn('text_ar', array_column(DefaultNarrativeQuestions::all(), 'text_ar'))
            ->when(Schema::hasTable('report_template_questions'), fn ($query) => $query->whereNotExists(
                fn ($used) => $used->select(DB::raw(1))
                    ->from('report_template_questions')
                    ->whereColumn('report_template_questions.narrative_question_id', 'narrative_questions.id'),
            ))
            ->delete();
    }
};
