<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NarrativeQuestionFrequency;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `is_active` and `sort_order` carry database defaults, so the definition
 * leaves them to the database (.ai/rules/seeders.md). The default question is
 * the project's own (no bank question); pass `narrative_question_id` for a
 * copy.
 *
 * @extends Factory<ReportTemplateQuestion>
 */
class ReportTemplateQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_template_id' => ReportTemplate::factory(),
            'text_ar' => 'سؤال المشروع '.fake()->unique()->numberBetween(1, 99999),
            'frequency' => NarrativeQuestionFrequency::Monthly,
        ];
    }

    /**
     * A question the project's reports no longer ask.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A question asked only in quarter-end monthly reports.
     */
    public function quarterEnd(): static
    {
        return $this->state(['frequency' => NarrativeQuestionFrequency::QuarterEnd]);
    }
}
