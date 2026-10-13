<?php

namespace Database\Factories;

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorMeasure;
use App\Models\LibraryIndicator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryIndicator>
 */
class LibraryIndicatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * An institutional (HC) entry, each at its own output level so codes
     * never collide: `HC.OP{n}.1`. Real codes come from IndicatorCode; pass
     * `program_id`, `level_ref`, `serial` and `code` together to place one.
     * Every default-bearing column (measure, collected_by, the flags) is left
     * to the database.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;

        $sequence++;

        return [
            'code' => "HC.OP{$sequence}.1",
            'program_id' => null,
            'level_ref' => "OP{$sequence}",
            'serial' => 1,
            'name_ar' => "عدد الأسر المستفيدة ({$sequence})",
            'name_en' => "Number of households reached ({$sequence})",
            'unit' => 'households',
            'frequency' => CollectionFrequency::Monthly,
        ];
    }

    /**
     * Indicate an entry taken off new selection.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Indicate a percentage entry: no unit, counted against the fixed target.
     */
    public function percentage(): static
    {
        return $this->state([
            'measure' => IndicatorMeasure::Percentage,
            'unit' => null,
            'denominator_basis' => DenominatorBasis::Fixed,
        ]);
    }

    /**
     * Indicate an average entry (a level with no 100 cap).
     */
    public function average(): static
    {
        return $this->state(['measure' => IndicatorMeasure::Average]);
    }
}
