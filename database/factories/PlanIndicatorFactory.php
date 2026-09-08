<?php

namespace Database\Factories;

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanIndicator>
 */
class PlanIndicatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A target is planned in an annual plan unless a caller says otherwise.
     * `period` is left to the column default, and `project_id` stays null until
     * the target is attributed to a project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => 'yearly_plan',
            'source_id' => YearlyPlan::factory(),
            'indicator_id' => Indicator::factory(),
            'target' => fake()->randomFloat(2, 100, 500000),
        ];
    }

    /**
     * Plan this target inside a donor proposal rather than an annual plan.
     */
    public function forProposal(?Proposal $proposal = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => 'proposal',
            'source_id' => $proposal?->getKey() ?? Proposal::factory(),
        ]);
    }

    /**
     * Plan this target inside the given annual plan.
     */
    public function forYearlyPlan(YearlyPlan $yearlyPlan): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => 'yearly_plan',
            'source_id' => $yearlyPlan->getKey(),
        ]);
    }
}
