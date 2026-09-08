<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['WASH', 'Shelter', 'Protection', 'Livelihoods', 'Health']),
            'strategy_id' => Strategy::factory(),
            'manager_id' => User::factory(),
        ];
    }
}
