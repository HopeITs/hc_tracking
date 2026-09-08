<?php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `status` and the actual dates are left to the column defaults so a fresh
     * proposal starts as an unstarted concept note.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $begin = fake()->dateTimeBetween('-2 years', '+6 months');

        return [
            'name' => fake()->randomElement(['Emergency WASH Response', 'Winterization Support', 'Rapid Shelter Repair']),
            'planned_date_begin' => $begin->format('Y-m-d'),
            'planned_date_end' => fn (array $attributes): string => CarbonImmutable::parse($attributes['planned_date_begin'])
                ->addYear()->toDateString(),
        ];
    }

    /**
     * Indicate that the proposal has been approved and is under way.
     *
     * The derived column is a closure rather than a value read from
     * $attributes directly, so it is resolved after the array passed to
     * create() has been merged and stays consistent with it.
     */
    public function running(): static
    {
        return $this->state([
            'status' => ProposalStatus::Approved,
            'actual_date_begin' => fn (array $attributes): string => $attributes['planned_date_begin'],
        ]);
    }
}
