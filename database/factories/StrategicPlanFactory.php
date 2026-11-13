<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Program;
use App\Models\StrategicPlan;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `status` carries a database default, so the definition leaves it to the
 * database (.ai/rules/seeders.md).
 *
 * @extends Factory<StrategicPlan>
 */
class StrategicPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'strategy_id' => Strategy::factory(),
            'program_id' => Program::factory(),
        ];
    }

    /**
     * A plan sent to the program manager.
     */
    public function submitted(): static
    {
        return $this->state(fn (): array => ['status' => 'submitted', 'submitted_at' => now()]);
    }

    /**
     * A plan the program manager approved.
     */
    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => 'approved', 'submitted_at' => now(), 'approved_at' => now()]);
    }
}
