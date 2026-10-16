<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NarrativeQuestionFrequency;
use App\Models\NarrativeQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `is_active` and `sort_order` carry database defaults, so the definition
 * leaves them to the database (.ai/rules/seeders.md); use `inactive()` for a
 * deactivated question. A question is monthly unless `quarterEnd()` says so.
 *
 * @extends Factory<NarrativeQuestion>
 */
class NarrativeQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'text_ar' => 'سؤال سردي '.fake()->unique()->numberBetween(1, 99999),
            'frequency' => NarrativeQuestionFrequency::Monthly,
        ];
    }

    /**
     * A question no new report template copies.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A question asked in every monthly report.
     */
    public function monthly(): static
    {
        return $this->state(['frequency' => NarrativeQuestionFrequency::Monthly]);
    }

    /**
     * A question asked only in quarter-end monthly reports.
     */
    public function quarterEnd(): static
    {
        return $this->state(['frequency' => NarrativeQuestionFrequency::QuarterEnd]);
    }
}
