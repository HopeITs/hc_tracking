<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityPlanBatch>
 */
class ActivityPlanBatchFactory extends Factory
{
    /**
     * Four weeks of 8 two-hour sessions from a Monday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::instance(fake()->dateTimeBetween('-3 months', '+3 months'))->startOfWeek(CarbonInterface::MONDAY);

        return [
            'activity_plan_item_id' => ActivityPlanItem::factory(),
            'start_week' => $start->toDateString(),
            'end_week' => $start->addWeeks(3)->toDateString(),
            'planned_count' => 8,
            'hours_each' => 2,
        ];
    }
}
