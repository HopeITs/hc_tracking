<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StrategicPillar;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `sort_order` carries a database default, so the definition leaves it to the
 * database (.ai/rules/seeders.md). Codes carry a sequence so two pillars of
 * one strategy never collide on the unique (strategy, code) index.
 *
 * @extends Factory<StrategicPillar>
 */
class StrategicPillarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;

        $sequence++;

        return [
            'strategy_id' => Strategy::factory(),
            'code' => "P{$sequence}",
            'name_ar' => "المحور {$sequence}",
        ];
    }
}
