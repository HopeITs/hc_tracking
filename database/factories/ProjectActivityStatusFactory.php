<?php

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Enums\ResultType;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\ProjectActivityStatus;
use App\Models\ProposalLocation;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectActivityStatus>
 */
class ProjectActivityStatusFactory extends Factory
{
    /**
     * An activity confirmed as started by a validated report of the same
     * project, on a location row of the activity's proposal carrying that
     * project (Y-15).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'result_id' => fn (): int => Result::factory()->activity(Result::factory()->type(ResultType::Output)->create())->create()->id,
            'proposal_location_id' => fn (array $attributes): int => ProposalLocation::factory()->create(array_filter([
                'project_id' => $attributes['project_id'] ?? null,
                'proposal_id' => Result::query()->whereKey($attributes['result_id'])->value('proposal_id'),
            ]))->id,
            'status' => ActivityStatus::Started,
            'monthly_report_id' => fn (array $attributes): int => MonthlyReport::factory()->validated()->create(['project_id' => $attributes['project_id']])->id,
        ];
    }

    /**
     * A status of the given location row, with the row's project.
     */
    public function forLocation(ProposalLocation $row): static
    {
        return $this->state([
            'proposal_location_id' => $row->id,
            'project_id' => $row->project_id,
        ]);
    }
}
