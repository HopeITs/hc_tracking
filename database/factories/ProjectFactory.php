<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Program;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * Define the model's default state.
     *
     * `status` is left to the column default so a new project is a draft and
     * the schema tests keep exercising that default. A draft has no dates
     * (contracts.md §3): use `approved()`, `active()` or `closed()` for a
     * dated project.
     *
     * The four demographic targets sum to `target_beneficiaries` here, but the
     * columns are independent by design and a caller may set them apart.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $men = fake()->numberBetween(200, 2000);
        $women = fake()->numberBetween(200, 2000);
        $boys = fake()->numberBetween(100, 1500);
        $girls = fake()->numberBetween(100, 1500);

        return [
            'name' => fake()->randomElement(['Water Network', 'Latrine Block', 'Cash Transfer', 'Mobile Clinic']).' '.fake()->randomElement(['Rehabilitation', 'Construction', 'Expansion']),
            'description' => fake()->paragraph(),
            'target_beneficiaries' => $men + $women + $boys + $girls,
            'target_men' => $men,
            'target_women' => $women,
            'target_boys' => $boys,
            'target_girls' => $girls,
            'city_id' => fn (): int => $this->createHrCity(),
            'program_id' => Program::factory(),
            'user_id' => fn (): int => $this->createHrEmployee(),
        ];
    }

    /**
     * Move this project to a given lifecycle state.
     */
    public function status(ProjectStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * Indicate a project currently being delivered.
     */
    public function active(): static
    {
        return $this->dated(ProjectStatus::Active, '-2 years', '-2 months', 180, 540);
    }

    /**
     * Indicate a project whose proposal was approved and is awaiting its
     * monthly report template. Dated, as approved projects always are.
     */
    public function approved(): static
    {
        return $this->dated(ProjectStatus::Approved, '+1 week', '+3 months', 270, 450);
    }

    /**
     * Indicate a project that has finished delivery.
     */
    public function closed(): static
    {
        return $this->dated(ProjectStatus::Closed, '-3 years', '-1 year', 60, 300);
    }

    /**
     * A dated state. The start is a plain value and the end is its own
     * closure over `start_date`, so an override of the start still gets an
     * end after it (see .ai/rules/factories.md).
     */
    private function dated(ProjectStatus $status, string $earliestStart, string $latestStart, int $minDays, int $maxDays): static
    {
        return $this->state([
            'status' => $status,
            'start_date' => fake()->dateTimeBetween($earliestStart, $latestStart)->format('Y-m-d'),
            'end_date' => fn (array $attributes): ?string => ($attributes['start_date'] ?? null) === null
                ? null
                : CarbonImmutable::parse($attributes['start_date'])->addDays(fake()->numberBetween($minDays, $maxDays))->toDateString(),
        ]);
    }

    /**
     * Indicate a project with a headline total but no disaggregation agreed.
     *
     * The two are allowed to disagree; this state makes that case reachable.
     */
    public function withoutDemographics(): static
    {
        return $this->state([
            'target_men' => null,
            'target_women' => null,
            'target_boys' => null,
            'target_girls' => null,
        ]);
    }
}
