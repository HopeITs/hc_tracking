<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');
});

test('my agenda merges my tasks, my projects\' milestones and monthly report deadlines, grouped by when', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Aleppo Wells', 'user_id' => $me->employee_id]));

    Activity::withoutRecording(function () use ($me, $project): void {
        $task = Task::factory()->create(['title' => 'Call the contractor', 'due_date' => '2026-09-16', 'created_by' => $me->id, 'status' => TaskStatus::Todo]);
        Task::factory()->create(['title' => 'Not mine at all', 'due_date' => '2026-09-17']);
        Milestone::factory()->create(['name' => 'Wells handover', 'project_id' => $project->id, 'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-09-25']);
        MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-09-01', 'status' => MonthlyReportStatus::Draft]);
    });

    $card = Livewire::actingAs($me)->test('desk.panels.coming', ['size' => 'L']);

    expect($card->instance()->events->pluck('kind')->all())->toBe(['task', 'milestone', 'monthly-report'])
        ->and($card->instance()->groupOf(CarbonImmutable::parse('2026-09-16')))->toBe('today')
        ->and($card->instance()->groupOf(CarbonImmutable::parse('2026-09-25')))->toBe('later');

    $card->assertSeeInOrder([__('desk.agenda_group.today'), 'Call the contractor', __('desk.agenda_group.later'), 'Wells handover'])
        ->assertDontSee('Not mine at all')
        ->set('facets', ['type' => __('desk.kind.milestone')])
        ->assertSee('Wells handover')
        ->assertDontSee('Call the contractor');
});

test('picking a day in the load strip narrows the list to it, and the month view never pages', function () {
    $me = userWithRole('Managers', insertHrEmployee());

    Activity::withoutRecording(function () use ($me): void {
        Task::factory()->create(['title' => 'Today thing', 'due_date' => '2026-09-16', 'created_by' => $me->id]);
        Task::factory()->create(['title' => 'Friday thing', 'due_date' => '2026-09-18', 'created_by' => $me->id]);
    });

    Livewire::actingAs($me)->test('desk.panels.coming', ['size' => 'M'])
        ->call('pickDay', '2026-09-18')
        ->assertSee('Friday thing')
        ->assertDontSee('Today thing')
        ->call('setView', 'month')
        ->assertSeeHtml('class="dk-mcal"');
});
