<?php

namespace Database\Factories;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\ReportTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReport>
 */
class MonthlyReportFactory extends Factory
{
    /**
     * Define the model's default state: a draft for the current month.
     * `status` is left to the column default. The template follows the
     * project (also one set through `for()`), so every month of a project
     * shares one template (D-76).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'report_template_id' => fn (array $attributes): int => $this->templateIdFor($attributes['project_id'] ?? null),
            'period' => now()->startOfMonth()->toDateString(),
        ];
    }

    /**
     * The project's template with the lowest id, made when it has none; a
     * report without a project gets a template of its own.
     */
    private function templateIdFor(mixed $projectId): int
    {
        if ($projectId === null) {
            return ReportTemplate::factory()->create()->id;
        }

        $templateId = ReportTemplate::query()->where('project_id', $projectId)->orderBy('id')->value('id');

        return $templateId !== null
            ? (int) $templateId
            : ReportTemplate::factory()->create(['project_id' => $projectId])->id;
    }

    /**
     * A report the manager submitted at the end of its month.
     *
     * Derived columns are per-attribute closures so they follow the period
     * passed to create() (.ai/rules/factories.md).
     */
    public function submitted(): static
    {
        return $this->state([
            'status' => MonthlyReportStatus::Submitted,
            'submitted_at' => fn (array $attributes): string => CarbonImmutable::parse($attributes['period'])->endOfMonth()->toDateTimeString(),
            'submitted_by' => User::factory(),
        ]);
    }

    /**
     * A report MEAL validated after it was submitted.
     */
    public function validated(): static
    {
        return $this->submitted()->state([
            'status' => MonthlyReportStatus::Validated,
            'validated_at' => fn (array $attributes): string => CarbonImmutable::parse($attributes['period'])->endOfMonth()->addDays(3)->toDateTimeString(),
            'validated_by' => User::factory(),
        ]);
    }
}
