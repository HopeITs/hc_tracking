<?php

namespace Database\Factories;

use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportLineShare>
 */
class MonthlyReportLineShareFactory extends Factory
{
    /**
     * An empty share of a line for one target; `not_measured` is left to the
     * column default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_line_id' => MonthlyReportLine::factory(),
            'plan_indicator_id' => PlanIndicator::factory(),
        ];
    }
}
