<?php

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Task;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');
});

/**
 * An open milestone ending on $end with $done of $total tasks done.
 */
function cardMilestone(string $name, string $end, int $done, int $total, MilestoneStatus $status = MilestoneStatus::Planned): Milestone
{
    return Activity::withoutRecording(function () use ($name, $end, $done, $total, $status): Milestone {
        $milestone = Milestone::factory()->create(['name' => $name, 'planned_start_date' => '2026-08-01', 'planned_end_date' => $end, 'status' => $status]);

        foreach (range(1, $total) as $index) {
            Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'status' => $index <= $done ? TaskStatus::Done : TaskStatus::Todo]);
        }

        return $milestone;
    });
}

test('milestones show progress and health, late first then soonest due, open ones only', function () {
    cardMilestone('Later and fine', '2026-10-30', 1, 4);
    cardMilestone('Due soon and behind', '2026-09-19', 1, 4);
    cardMilestone('Already late', '2026-09-10', 3, 4, MilestoneStatus::Ongoing);
    cardMilestone('Postponed one', '2026-09-12', 0, 1, MilestoneStatus::Postponed);

    $card = Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.milestones', ['size' => 'L']);

    expect($card->instance()->rows->map(fn (array $row): array => [$row['milestone']->name, $row['percent'], $row['health']])->all())->toBe([
        ['Already late', 75, 'bad'],
        ['Due soon and behind', 25, 'warn'],
        ['Later and fine', 25, 'good'],
    ]);

    $card->assertSee('75%')
        ->assertDontSee('Postponed one')
        ->set('facets', ['health' => __('desk.health.warn')])
        ->assertSee('Due soon and behind')
        ->assertDontSee('Already late');
});
