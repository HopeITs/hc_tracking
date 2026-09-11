<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\City;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `status` is left to the column default so a new project is a draft and
     * the schema tests keep exercising that default. Use the named states to
     * move it.
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

        $start = fake()->dateTimeBetween('-2 years', '-2 months');

        return [
            'name' => fake()->randomElement(['Water Network', 'Latrine Block', 'Cash Transfer', 'Mobile Clinic']).' '.fake()->randomElement(['Rehabilitation', 'Construction', 'Expansion']),
            'description' => fake()->paragraph(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween($start, '+1 year')->format('Y-m-d'),
            'target_beneficiaries' => $men + $women + $boys + $girls,
            'target_men' => $men,
            'target_women' => $women,
            'target_boys' => $boys,
            'target_girls' => $girls,
            'city_id' => City::factory(),
            'program_id' => Program::factory(),
            'user_id' => User::factory(),
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
        return $this->status(ProjectStatus::Active);
    }

    /**
     * Indicate a project that has finished delivery.
     */
    public function closed(): static
    {
        return $this->status(ProjectStatus::Closed);
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
