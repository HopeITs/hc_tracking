<?php

namespace Database\Factories;

use App\Models\ProposalLocation;
use App\Models\ScheduleBaseline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleBaseline>
 */
class ScheduleBaselineFactory extends Factory
{
    /**
     * A baseline of one place's schedule (Y-12, D-93).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_location_id' => ProposalLocation::factory(),
            'name' => fake()->randomElement(['Approved plan', 'Inception baseline', 'Revised plan']),
        ];
    }
}
