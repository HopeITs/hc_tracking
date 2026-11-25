<?php

namespace Database\Factories;

use App\Models\MonthlyReport;
use App\Models\MonthlyReportRisk;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportRisk>
 */
class MonthlyReportRiskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_id' => MonthlyReport::factory(),
            'risk_id' => Risk::factory(),
            'happened' => false,
        ];
    }
}
