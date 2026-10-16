<?php

namespace Database\Factories;

use App\Models\MonthlyReport;
use App\Models\MonthlyReportReopenRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportReopenRequest>
 */
class MonthlyReportReopenRequestFactory extends Factory
{
    /**
     * A pending request on a validated report; `status` is left to the column
     * default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_id' => MonthlyReport::factory()->validated(),
            'requested_by' => User::factory(),
            'reason' => fake()->sentence(8),
        ];
    }
}
