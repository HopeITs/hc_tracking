<?php

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Enums\ResultType;
use App\Models\MonthlyReport;
use App\Models\ProjectActivityStatus;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectActivityStatus>
 */
class ProjectActivityStatusFactory extends Factory
{
    /**
     * An activity confirmed as started by a validated report at the place of
     * the location row that carries it (Y-15).
     *
     * The row is the driver (Y-18): it used to be derived from a project, and
     * the report used to be the project's. Both now hang off the row, so the
     * fixture builds no project at all.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'result_id' => fn (): int => Result::factory()->activity(Result::factory()->type(ResultType::Output)->create())->create()->id,
            'status' => ActivityStatus::Started,
        ];
    }

    /**
     * The row and the report that confirms it are linked once the attributes
     * are resolved, so neither closure has to read the other's key
     * (.ai/rules/factories.md).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ProjectActivityStatus $status): void {
            // A result under a program carries no proposal, and the row then
            // takes a proposal of its own from the factory default.
            $status->proposal_location_id ??= ProposalLocation::factory()->complete()->create(array_filter([
                'proposal_id' => Result::query()->whereKey($status->result_id)->value('proposal_id'),
            ]))->id;

            $status->monthly_report_id ??= $this->validatedReportAt((int) $status->proposal_location_id);
        });
    }

    /**
     * A status of the given location row.
     */
    public function forLocation(ProposalLocation $row): static
    {
        return $this->state(['proposal_location_id' => $row->id]);
    }

    /**
     * A validated monthly report at the row's place, through a template of
     * the row's proposal (D-76): the report that confirms the activity.
     */
    private function validatedReportAt(int $rowId): int
    {
        $row = ProposalLocation::query()->findOrFail($rowId);

        $template = ReportTemplate::factory()
            ->forPlace($row->place(), $row->proposal)
            ->create();

        return MonthlyReport::factory()->validated()->create([
            'report_template_id' => $template->id,
            'project_id' => null,
        ])->id;
    }
}
