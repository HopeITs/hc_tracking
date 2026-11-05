<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserTodo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTodo>
 */
class UserTodoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `is_done` and `sort_order` are left to the column defaults, so a new to-do starts open.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->randomElement(['Call the donor back', 'Send the monthly report', 'Book the meeting room', 'Check the field photos']),
        ];
    }

    /**
     * A to-do already ticked.
     */
    public function done(): static
    {
        return $this->state([
            'is_done' => true,
            'done_at' => now(),
        ]);
    }
}
