<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\ProposalLocation;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivitySchedule>
 */
class ActivityScheduleFactory extends Factory
{
    /**
     * Define the model's default state: a whole month on the proposal
     * calendar of a new activity (under a new output, as an activity needs).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'result_id' => fn (): int => Result::factory()->activity(Result::factory()->type(ResultType::Output)->create())->create()->id,
            'month' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-01'),
        ];
    }

    /**
     * A month of the row's own calendar (an override, Y-15), with the row's
     * project still written until Y-18.
     */
    public function forLocation(ProposalLocation $row): static
    {
        return $this->state([
            'proposal_location_id' => $row->id,
            'project_id' => $row->project_id,
        ]);
    }
}
