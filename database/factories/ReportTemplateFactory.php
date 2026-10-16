<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportTemplateStatus;
use App\Models\Project;
use App\Models\ReportTemplate;
use App\Models\User;
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
        ];
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
