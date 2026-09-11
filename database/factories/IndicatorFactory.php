<?php

namespace Database\Factories;

use App\Enums\Period;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `measure` and `frequency` are left to the column defaults. `indicators`
     * is unique on (result_id, name), so the name carries a sequence number. A
     * Faker unique() modifier would overflow once its word pool were exhausted.
     *
     * `project_id` is left null: most indicators sit at outcome level and
     * belong to no single project. Use forProject() for the other case.
     *
     * `baseline` and `target` are nullable with no database default, so setting
     * them here exercises nothing the schema tests assert — and an indicator
     * without them cannot be rated, which makes them worth having by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;

        $sequence++;

        $baseline = fake()->randomFloat(2, 0, 5000);

        return [
            'name' => "Number of people reached ({$sequence})",
            'result_id' => Result::factory(),
            'unit' => fake()->randomElement(['people', 'households', 'litres/day', 'sessions', 'percent']),
            'baseline' => $baseline,
            'target' => $baseline + fake()->randomFloat(2, 1000, 40000),
        ];
    }

    /**
     * Scope this indicator to a project as well as its result.
     */
    public function forProject(int $projectId): static
    {
        return $this->state(['project_id' => $projectId]);
    }

    /**
     * Measure this indicator on a given cadence.
     */
    public function measuredEvery(Period $frequency): static
    {
        return $this->state(['frequency' => $frequency]);
    }

    /**
     * Indicate an indicator with no baseline or target set yet.
     *
     * Keeps the "cannot be rated" path reachable: percentAchieved() returns
     * null here, and the UI must render an em dash rather than a zero.
     */
    public function untargeted(): static
    {
        return $this->state(['baseline' => null, 'target' => null]);
    }
}
