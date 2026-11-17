<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalLocation>
 */
class ProposalLocationFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * A row with only its city: program, responsible, centers and project
     * are what the proposal fills in before approval. `is_new` and `sort`
     * are left to the column defaults.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'city_id' => fn (): int => $this->createHrCity(),
        ];
    }

    /**
     * A row that still needs a project.
     */
    public function isNew(): static
    {
        return $this->state(['is_new' => true]);
    }

    /**
     * Field work, no center (D-88): the row runs across the city.
     */
    public function cityWide(): static
    {
        return $this->state(['city_wide' => true]);
    }

    /**
     * A complete row under a program with a responsible employee.
     */
    public function complete(): static
    {
        return $this->state([
            'program_id' => Program::factory(),
            'responsible_employee_id' => fn (): int => $this->createHrEmployee(),
        ]);
    }

    /**
     * A row standing for an existing project, with the project's placement.
     */
    public function forProject(Project $project): static
    {
        return $this->state([
            'city_id' => $project->city_id,
            'program_id' => $project->program_id,
            'responsible_employee_id' => $project->user_id,
            'project_id' => $project->id,
            'is_new' => false,
        ]);
    }
}
