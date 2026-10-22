<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Risk>
 */
class RiskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `happened` is left to the column default so a new risk starts unrealised.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Access restrictions', 'Currency devaluation', 'Supplier delay', 'Staff turnover']),
            'project_id' => Project::factory(),
            'impact' => fake()->sentence(),
        ];
    }

    /**
     * Indicate how likely the risk is and how hard it would hit, each 1–3.
     */
    public function rated(int $likelihood, int $severity): static
    {
        return $this->state(fn (array $attributes): array => [
            'likelihood' => $likelihood,
            'severity' => $severity,
        ]);
    }

    /**
     * Indicate that the risk materialised and was mitigated.
     */
    public function happened(): static
    {
        return $this->state(fn (array $attributes): array => [
            'happened' => true,
            'solution' => fake()->sentence(),
        ]);
    }
}
