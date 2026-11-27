<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\ProposalLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanUpdate>
 */
class PlanUpdateFactory extends Factory
{
    /**
     * A draft update of an approved plan for a new centre, at one place
     * (Y-12, D-93).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_plan_id' => ActivityPlan::factory()->approved(),
            'proposal_location_id' => ProposalLocation::factory(),
            'month' => '2027-01-01',
        ];
    }
}
