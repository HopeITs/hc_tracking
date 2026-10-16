<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\ReportTemplateLineStatus;
use App\Models\PlanIndicator;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `status` (`pending`) and `schedule` (`library_default`) carry database
 * defaults, so the definition leaves them to the database
 * (.ai/rules/seeders.md); use the named states to set them. The default
 * target is an annual-plan target with no library link; pass
 * `plan_indicator_id` for a library-linked one.
 *
 * @extends Factory<ReportTemplateLine>
 */
class ReportTemplateLineFactory extends Factory
{
    /**
     * Define the model's default state: a pending line for 2026.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_template_id' => ReportTemplate::factory(),
            'plan_indicator_id' => PlanIndicator::factory(),
            'entered_by' => IndicatorCollector::ProjectTeam,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ];
    }

    /**
     * A line MEAL has confirmed.
     */
    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportTemplateLineStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by' => User::factory(),
        ]);
    }

    /**
     * A confirmed line that has stopped.
     */
    public function retired(): static
    {
        return $this->confirmed()->state(['status' => ReportTemplateLineStatus::Retired]);
    }

    /**
     * Due every month, whatever the library frequency.
     */
    public function monthly(): static
    {
        return $this->state(['schedule' => LineSchedule::Monthly, 'schedule_months' => null, 'once_after_months' => null]);
    }

    /**
     * Due in the given calendar months (1–12).
     *
     * @param  list<int>  $months
     */
    public function inMonths(array $months): static
    {
        return $this->state(['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => $months, 'once_after_months' => null]);
    }

    /**
     * Due once, the given number of months after the first month.
     */
    public function onceAfter(int $months): static
    {
        return $this->state(['schedule' => LineSchedule::OnceAfterMonths, 'schedule_months' => null, 'once_after_months' => $months]);
    }
}
