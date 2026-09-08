<?php

namespace Database\Factories;

use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The three boolean flags are left to the column defaults so a new report
     * starts unapproved, editable and unseen by MEAL.
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
     * Indicate that the report was submitted and signed off.
     *
     * The derived column is a closure rather than a value read from
     * $attributes directly, so it is resolved after the array passed to
     * create() has been merged and stays consistent with it.
     */
    public function approved(): static
    {
        return $this->state([
            'submit_date' => fn (array $attributes): string => $attributes['planned_date'],
            'is_approved' => true,
            'approved_date' => fn (array $attributes): string => $attributes['planned_date'],
            'is_editable' => false,
            'is_seen_by_meal' => true,
        ]);
    }
}
