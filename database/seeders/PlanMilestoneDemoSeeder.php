<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivityPlanStatus;
use App\Models\Activity;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanMilestone;
use App\Models\PlanIndicator;
use App\Models\User;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo checkpoints on every approved annual plan, so the plan page and the
 * managers' Desk panel show each state: one missed, one reached, one due in
 * three days, and (when the plan's proposal has a target) one counted from
 * the approved reports.
 *
 * Plans are made by hand in the app, so this is not part of DatabaseSeeder.
 * Run it after approving a plan:
 *
 *     php artisan db:seed --class=PlanMilestoneDemoSeeder
 *
 * Safe to run again: each milestone is matched on its plan and title.
 */
class PlanMilestoneDemoSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();

        Activity::withoutRecording(function () use ($today): void {
            ActivityPlan::query()
                ->where('status', ActivityPlanStatus::Approved->value)
                ->get()
                ->each(function (ActivityPlan $plan) use ($today): void {
                    $creatorId = $this->creatorFor($plan);

                    $this->milestone($plan, $creatorId, 'Baseline survey finished', $today->subDays(10), 'checkpoint');
                    $this->milestone($plan, $creatorId, 'Centres staffed and trained', $today->subDays(20), 'checkpoint', completedAt: $today->subDays(22));
                    $this->milestone($plan, $creatorId, 'Mid-year review held', $today->addDays(3), 'checkpoint');

                    $target = PlanIndicator::query()
                        ->where('source_type', 'proposal')
                        ->where('source_id', $plan->proposal_id)
                        ->where('target', '>', 0)
                        ->orderBy('id')
                        ->first();

                    if ($target !== null) {
                        $this->milestone($plan, $creatorId, 'Half of the yearly target reached', $today->addDays(14), 'percentage', $target, 50);
                    }
                });
        });
    }

    private function milestone(ActivityPlan $plan, ?int $creatorId, string $title, CarbonImmutable $due, string $measure, ?PlanIndicator $target = null, ?float $threshold = null, ?CarbonImmutable $completedAt = null): void
    {
        ActivityPlanMilestone::query()->firstOrCreate(
            ['activity_plan_id' => $plan->id, 'title' => $title],
            [
                'created_by' => $creatorId,
                'description' => 'Demo milestone.',
                'due_date' => $due->toDateString(),
                'measure' => $measure,
                'plan_indicator_id' => $target?->id,
                'threshold' => $threshold,
                'completed_at' => $completedAt,
            ],
        );
    }

    /**
     * The program manager's account, else any administrator.
     */
    private function creatorFor(ActivityPlan $plan): ?int
    {
        $managers = app(RecipientResolver::class)->programManagerUserIds($plan->program_id);

        return $managers[0] ?? User::query()->role('Super Admin')->value('id');
    }
}
