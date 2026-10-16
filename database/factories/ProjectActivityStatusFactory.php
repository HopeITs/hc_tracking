<?php

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Enums\ResultType;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\ProjectActivityStatus;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectActivityStatus>
 */
class ProjectActivityStatusFactory extends Factory
{
    /**
     * An activity confirmed as started by a validated report of the same
     * project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'result_id' => fn (): int => Result::factory()->activity(Result::factory()->type(ResultType::Output)->create())->create()->id,
            'status' => ActivityStatus::Started,
            'monthly_report_id' => fn (array $attributes): int => MonthlyReport::factory()->validated()->create(['project_id' => $attributes['project_id']])->id,
        ];
    }
}
