<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Strategy;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * Define the model's default state.
     *
     * Programs are tracking-owned since W-34b (D-68), so the factory writes
     * the row here and nothing is inserted into the HR database — the old
     * "create the HR program first, then the tracking row under its id" dance
     * is gone. `name_ar` is left null, as it is on everything the one-time
     * import brought over from HR, which had a single name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_en' => fake()->randomElement(['WASH', 'Shelter', 'Protection', 'Livelihoods', 'Health']),
            'code' => fake()->bothify('??####'),
            'strategy_id' => Strategy::factory(),
            'manager_id' => fn (): int => $this->createHrEmployee(),
        ];
    }

    /**
     * Indicate a program nobody has linked to a strategy or a manager yet.
     */
    public function unassigned(): static
    {
        return $this->state([
            'strategy_id' => null,
            'manager_id' => null,
        ]);
    }

    /**
     * Indicate a program that is no longer in use.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
