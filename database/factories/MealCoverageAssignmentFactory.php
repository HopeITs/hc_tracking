<?php

namespace Database\Factories;

use App\Enums\MealCoverageScope;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Support\Reporting\ReportPlace;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealCoverageAssignment>
 */
class MealCoverageAssignmentFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * Define the model's default state: a governorate officer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_type' => MealCoverageScope::City,
            'city_id' => fn (): int => $this->createHrCity(),
            'employee_id' => fn (): int => $this->createHrEmployee(),
        ];
    }

    /**
     * The officer of the given governorate (HR city).
     */
    public function forCity(?int $cityId = null): static
    {
        return $this->state(fn (): array => [
            'scope_type' => MealCoverageScope::City,
            'city_id' => $cityId ?? $this->createHrCity(),
            'project_id' => null,
        ]);
    }

    /**
     * The officer of the given project, overriding its governorate's.
     */
    public function forProject(?int $projectId = null): static
    {
        return $this->state(fn (): array => [
            'scope_type' => MealCoverageScope::Project,
            'city_id' => null,
            'project_id' => $projectId ?? Project::factory(),
        ]);
    }

    public function forPlace(ReportPlace $place): static
    {
        return $this->state([
            'scope_type' => MealCoverageScope::Place,
            'city_id' => $place->cityId,
            'program_id' => $place->programId,
            'center_id' => $place->centerId,
            'city_wide' => $place->cityWide,
            'scope_key' => 'place:'.$place->key(),
            'project_id' => null,
        ]);
    }
}
