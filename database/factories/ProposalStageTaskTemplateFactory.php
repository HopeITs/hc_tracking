<?php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Enums\StageTemplateCreates;
use App\Enums\TaskKind;
use App\Models\ProposalStageTaskTemplate;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalStageTaskTemplate>
 */
class ProposalStageTaskTemplateFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * An active review task on `proposal review`, due a week after the move.
     * Columns with a database default are left out so the default is what the
     * tests read back (see .ai/rules/seeders.md).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => ProposalStatus::ProposalReview,
            'department_id' => fn (): int => $this->createHrDepartment(),
            'kind' => TaskKind::General,
            'title_en' => 'Review the proposal',
            'title_ar' => 'راجع المقترح',
            'due_offset_days' => 7,
        ];
    }

    /**
     * The template that opens the proposal's final-report milestone.
     */
    public function finalReportMilestone(): static
    {
        return $this->state([
            'status' => ProposalStatus::Approved,
            'creates' => StageTemplateCreates::FinalReportMilestone,
            'title_en' => 'Prepare the final report',
            'title_ar' => 'جهّز التقرير النهائي',
            'due_offset_days' => 30,
        ]);
    }

    /**
     * A template the PR Manager has switched off.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A template whose task is created again each time the status is re-entered.
     */
    public function repeating(): static
    {
        return $this->state(['repeat_on_reentry' => true]);
    }
}
