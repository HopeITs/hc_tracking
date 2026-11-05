<?php

namespace Database\Factories;

use App\Models\AiDraft;
use App\Models\MonthlyReportAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiDraft>
 */
class AiDraftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_answer_id' => MonthlyReportAnswer::factory(),
            'drafted_text' => 'This month, 134 children attended psychosocial sessions.',
        ];
    }
}
