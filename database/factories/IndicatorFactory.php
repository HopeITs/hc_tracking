<?php

namespace Database\Factories;

use App\Models\Indicator;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `measure` is left to the column default. `indicators` is unique on
     * (result_id, name), so the name carries a sequence number. A Faker
     * unique() modifier would overflow once its word pool were exhausted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;

        $sequence++;

        return [
            'name' => "Number of people reached ({$sequence})",
            'result_id' => Result::factory(),
        ];
    }
}
