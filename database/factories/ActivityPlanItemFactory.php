<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanItem;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityPlanItem>
 */
class ActivityPlanItemFactory extends Factory
{
    /**
     * A session activity of a new plan, on a new logframe activity.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_plan_id' => ActivityPlan::factory(),
            'result_id' => fn (): int => Result::factory()->activity(Result::factory()->type(ResultType::Output)->create())->create()->id,
        ];
    }
}
