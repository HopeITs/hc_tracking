<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FollowUpTrigger;
use App\Models\ActivityPlanFollowUp;
use App\Models\ActivityPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityPlanFollowUp>
 */
class ActivityPlanFollowUpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_plan_item_id' => ActivityPlanItem::factory(),
            'activity_plan_id' => fn (array $attributes): int => (int) ActivityPlanItem::query()->whereKey($attributes['activity_plan_item_id'])->value('activity_plan_id'),
            'trigger' => FollowUpTrigger::Finish->value,
            'description' => 'Run the post test',
        ];
    }
}
