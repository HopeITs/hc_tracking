<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Strategy;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * Define the model's default state.
     *
     * A program mirrors an HR program under the same id, so the factory writes
     * the HR row first. That only works against the test suite's in-memory HR
     * database: the real `hr` connection is READ ONLY and rejects the insert,
     * which is why seeders mirror real HR programs through Program::syncFromHr().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['WASH', 'Shelter', 'Protection', 'Livelihoods', 'Health']),
            'id' => fn (array $attributes): int => DB::connection('hr')->table('programs')->insertGetId([
                'name' => $attributes['name'],
                'code' => fake()->bothify('??####'),
                'slug' => fake()->unique()->slug(),
            ]),
            'strategy_id' => Strategy::factory(),
            'manager_id' => fn (): int => $this->createHrEmployee(),
        ];
    }

    /**
     * Indicate a program mirrored from HR that nobody has linked yet.
     */
    public function unassigned(): static
    {
        return $this->state([
            'strategy_id' => null,
            'manager_id' => null,
        ]);
    }
}
