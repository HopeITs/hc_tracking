<?php

namespace Database\Factories;

use App\Models\Assumption;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assumption>
 */
class AssumptionFactory extends Factory
{
    /**
     * `status` is left to the column default (holds).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'result_id' => fn (array $attributes): int => Result::factory()->create(['proposal_id' => $attributes['proposal_id']])->id,
            'statement' => fake()->sentence(),
        ];
    }
}
