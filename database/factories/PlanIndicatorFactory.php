<?php

namespace Database\Factories;

use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Support\Reporting\PlaceBridge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;

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
            'source_type' => 'annual_plan',
            'source_id' => AnnualPlan::factory(),
            'indicator_id' => Indicator::factory(),
            'target' => fake()->randomFloat(2, 100, 500000),
        ];
    }

    /**
     * A target attributed to a project carries the place the writers would
     * give it (Y-08, PlaceBridge::placeColumnsForTargets()), unless the caller
     * sets one. Build the proposal's location rows before its targets.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (PlanIndicator $target): void {
            if ($target->project_id === null || $target->city_id !== null) {
                return;
            }

            $class = Relation::getMorphedModel($target->source_type);
            $source = $class === null ? null : $class::query()->find($target->source_id);

            if ($source === null) {
                return;
            }

            $target->fill(app(PlaceBridge::class)->placeColumnsForTargets($source, [$target->project_id])[$target->project_id]);
        });
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
    public function forAnnualPlan(AnnualPlan $annualPlan): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => 'annual_plan',
            'source_id' => $annualPlan->getKey(),
        ]);
    }
}
