<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineBreakdown;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportLineBreakdown>
 */
class MonthlyReportLineBreakdownFactory extends Factory
{
    /**
     * A line-level male count with persons with disability among them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_line_id' => MonthlyReportLine::factory(),
            'gender' => Gender::Male,
            'value' => '10.00',
            'disabled_value' => '2.00',
        ];
    }
}
