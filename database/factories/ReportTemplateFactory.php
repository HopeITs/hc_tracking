<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportTemplateStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `status` carries a database default (`pending`), so the definition leaves
 * it to the database (.ai/rules/seeders.md); use `confirmed()` for a
 * confirmed template.
 *
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    /**
     * Define the model's default state: a pending template.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->approved(),
            'city_id' => fn (array $attributes): int => (int) Project::query()->whereKey($attributes['project_id'])->value('city_id'),
            'program_id' => fn (array $attributes): int => (int) Project::query()->whereKey($attributes['project_id'])->value('program_id'),
        ];
    }

    /**
     * A template of the Y-02 kind: at the place, carried by the proposal,
     * with no legacy project.
     */
    public function forPlace(ReportPlace $place, Proposal $proposal): static
    {
        return $this->state([
            'project_id' => null,
            'city_id' => $place->cityId,
            'program_id' => $place->programId,
            'center_id' => $place->centerId,
            'city_wide' => $place->cityWide,
        ])->afterCreating(fn (ReportTemplate $template) => $template->proposals()->attach($proposal->id, ['joined_at' => now()]));
    }

    /**
     * A template MEAL has confirmed.
     */
    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportTemplateStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by' => User::factory(),
        ]);
    }
}
