<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `status` and `is_seen_by_meal` are left to the column defaults so a new
     * report starts as an unseen draft and the schema tests keep exercising
     * those defaults.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_indicator_id' => PlanIndicator::factory(),
            'user_id' => User::factory(),
            'planned_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'value' => fake()->randomFloat(2, 0, 250000),
            'narrative_report_notes' => fake()->sentence(),
        ];
    }

    /**
     * Indicate that the report has been reviewed but not yet signed off.
     */
    public function reviewed(): static
    {
        return $this->state([
            'status' => ReportStatus::Reviewed,
            'submit_date' => fn (array $attributes): string => $attributes['planned_date'],
        ]);
    }

    /**
     * Indicate that the report was submitted and signed off.
     *
     * The derived column is a per-attribute closure rather than a value read
     * from $attributes directly, so it is resolved after the array passed to
     * create() has been merged and stays consistent with it. See
     * .ai/rules/factories.md.
     */
    public function approved(): static
    {
        return $this->state([
            'status' => ReportStatus::Approved,
            'submit_date' => fn (array $attributes): string => $attributes['planned_date'],
            'is_seen_by_meal' => true,
        ]);
    }

    /**
     * Fill in the five narrative sections a donor report is made of.
     */
    public function withNarrative(): static
    {
        return $this->state([
            'period_start' => fn (array $attributes): string => CarbonImmutable::parse($attributes['planned_date'])->subMonths(3)->toDateString(),
            'period_end' => fn (array $attributes): string => $attributes['planned_date'],
            'key_achievements' => fake()->paragraph(),
            'challenges' => fake()->paragraph(),
            'lessons_learned' => fake()->paragraph(),
            'next_period_plans' => fake()->paragraph(),
        ]);
    }
}
