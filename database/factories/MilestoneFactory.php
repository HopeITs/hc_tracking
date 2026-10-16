<?php

namespace Database\Factories;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * Define the model's default state.
     *
     * `status`, `sequence` and `weight_percentage` are left to the column
     * defaults so a new milestone starts pending, first and unweighted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['Baseline assessment', 'Community mobilisation', 'Works handover', 'Endline survey']),
            'description' => fake()->sentence(),
            'planned_start_date' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'responsible_user_id' => fn (): int => $this->createHrEmployee(),
        ];
    }

    /**
     * Nest the milestone under an existing one.
     *
     * The parent's project is copied across because milestones.project_id and
     * parent_milestone_id are independent columns, and MilestoneObserver
     * rejects a child that sits in a different project.
     */
    public function childOf(Milestone $parent): static
    {
        return $this->state([
            'project_id' => $parent->project_id,
            'proposal_id' => $parent->proposal_id,
            'parent_milestone_id' => $parent->id,
        ]);
    }

    /**
     * A milestone shared at proposal level (contracts.md §12.2): no project,
     * dated inside the proposal's planned period.
     */
    public function forProposal(Proposal $proposal): static
    {
        return $this->state([
            'project_id' => null,
            'proposal_id' => $proposal->id,
            'planned_start_date' => $proposal->planned_date_begin->toDateString(),
            'planned_end_date' => $proposal->planned_date_begin->addMonth()->toDateString(),
        ]);
    }

    /**
     * Indicate that the milestone was reached on its planned end date.
     *
     * The derived columns are closures rather than values read from
     * $attributes directly, so they are resolved after the array passed to
     * create() has been merged and stay consistent with it. MilestoneObserver
     * only back-fills actual dates when they are left unset, so a state that
     * declares 'reached' without them would otherwise leave both null.
     */
    public function reached(): static
    {
        return $this->state([
            'status' => MilestoneStatus::Reached,
            'actual_start_date' => fn (array $attributes): string => $attributes['planned_start_date'],
            'actual_end_date' => fn (array $attributes): string => $attributes['planned_end_date'] ?? $attributes['planned_start_date'],
        ]);
    }
}
