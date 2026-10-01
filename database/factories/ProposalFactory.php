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
     * `internal_project_number` is NOT NULL with no default, so it is always
     * set. Factory numbers use 100–899: real proposals use 001–034 and demo
     * proposals 901+, so the three never collide.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $begin = fake()->dateTimeBetween('-2 years', '+6 months');

        return [
            'name' => fake()->randomElement(['Emergency WASH Response', 'Winterization Support', 'Rapid Shelter Repair']),
            'internal_project_number' => 'HC-2026-'.fake()->unique()->numberBetween(100, 899),
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
