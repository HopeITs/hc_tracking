<?php

namespace Database\Factories;

use App\Models\ScheduleBaseline;
use App\Models\ScheduleBaselineItem;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleBaselineItem>
 */
class ScheduleBaselineItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 months', '+3 months');

        return [
            'schedule_baseline_id' => ScheduleBaseline::factory(),
            'item_type' => 'task',
            'item_id' => Task::factory(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => (clone $start)->modify('+14 days')->format('Y-m-d'),
        ];
    }
}
