<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Water Network', 'Latrine Block', 'Cash Transfer', 'Mobile Clinic']).' '.fake()->randomElement(['Rehabilitation', 'Construction', 'Expansion']),
            'city_id' => City::factory(),
            'program_id' => Program::factory(),
            'user_id' => User::factory(),
        ];
    }
}
