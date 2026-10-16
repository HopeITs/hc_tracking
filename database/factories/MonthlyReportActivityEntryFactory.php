<?php

namespace Database\Factories;

use App\Enums\ResultType;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportActivityEntry>
 */
class MonthlyReportActivityEntryFactory extends Factory
{
    /**
     * An activity listed in a report with no status picked yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_id' => MonthlyReport::factory(),
            'result_id' => fn (): int => Result::factory()->activity(Result::factory()->type(ResultType::Output)->create())->create()->id,
        ];
    }
}
