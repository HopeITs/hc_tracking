<?php

namespace Database\Factories;

use App\Models\DonorProposal;
use App\Models\DonorReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorReport>
 */
class DonorReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `type` is left to the column default, so a report is narrative unless a
     * caller says otherwise, and it starts unsubmitted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_proposal_id' => DonorProposal::factory(),
            'title' => fake()->randomElement(['Interim report', 'Final report', 'Quarterly update']),
            'due_on' => fake()->dateTimeBetween('-6 months', '+6 months')->format('Y-m-d'),
        ];
    }

    /**
     * Indicate that the report went out on its due date.
     *
     * A closure, so it follows whatever due_on the caller supplies.
     */
    public function submitted(): static
    {
        return $this->state([
            'submitted_on' => fn (array $attributes): string => (string) $attributes['due_on'],
        ]);
    }
}
