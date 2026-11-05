<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RiskLibrary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `is_active` and `sort_order` carry database defaults, so the definition
 * leaves them to the database (.ai/rules/seeders.md); use `inactive()` for a
 * deactivated entry.
 *
 * @extends Factory<RiskLibrary>
 */
class RiskLibraryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99999);

        return [
            'title_en' => 'Library risk '.$number,
            'title_ar' => 'خطر '.$number,
        ];
    }

    /**
     * An entry taken off new selection.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
