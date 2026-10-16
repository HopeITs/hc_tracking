<?php

namespace Database\Factories;

use App\Enums\IndicatorCollector;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportLine>
 */
class MonthlyReportLineFactory extends Factory
{
    /**
     * An empty project-team line; `not_measured` is left to the column default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_id' => MonthlyReport::factory(),
            'library_indicator_id' => LibraryIndicator::factory(),
            'entered_by' => IndicatorCollector::ProjectTeam,
        ];
    }

    /**
     * A line MEAL fills during validation (D-48a).
     */
    public function meal(): static
    {
        return $this->state(['entered_by' => IndicatorCollector::Meal]);
    }
}
