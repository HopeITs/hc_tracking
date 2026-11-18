<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');
});

test('my agenda merges my tasks and monthly report deadlines, grouped by when', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Aleppo Wells', 'user_id' => $me->employee_id]));

    Activity::withoutRecording(function () use ($me, $project): void {
        $task = Task::factory()->create(['title' => 'Call the contractor', 'due_date' => '2026-09-16', 'created_by' => $me->id, 'status' => TaskStatus::Todo]);
        Task::factory()->create(['title' => 'Not mine at all', 'due_date' => '2026-09-17']);
        MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-09-01', 'status' => MonthlyReportStatus::Draft]);
    });

    $card = Livewire::actingAs($me)->test('desk.panels.coming', ['size' => 'L']);

    expect($card->instance()->events->pluck('kind')->all())->toBe(['task', 'monthly-report'])
        ->and($card->instance()->groupOf(CarbonImmutable::parse('2026-09-16')))->toBe('today')
        ->and($card->instance()->groupOf(CarbonImmutable::parse('2026-09-25')))->toBe('later');

    $card->assertSeeInOrder([__('desk.agenda_group.today'), 'Call the contractor'])
        ->assertDontSee('Not mine at all')
        ->set('facets', ['type' => __('desk.kind.monthly-report')])
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

test('the agenda offers a project filter from its tasks and clears it on request', function () {
    $manager = userWithRole('Managers', insertHrEmployee());
    $alpha = Project::factory()->create(['name' => 'Alpha project']);
    $beta = Project::factory()->create(['name' => 'Beta project']);
    Task::factory()->create([
        'source_type' => 'project', 'source_id' => $alpha->id, 'created_by' => $manager->id,
        'title' => 'Call Alpha contractor', 'due_date' => '2026-09-16', 'status' => TaskStatus::Todo,
    ]);
    Task::factory()->create([
        'source_type' => 'project', 'source_id' => $beta->id, 'created_by' => $manager->id,
        'title' => 'Call Beta contractor', 'due_date' => '2026-09-17', 'status' => TaskStatus::Todo,
    ]);

    Livewire::actingAs($manager)->test('desk.panels.coming', ['size' => 'L'])
        ->assertSeeHtml('data-panel-filter')
        ->assertSee('Call Alpha contractor')
        ->assertSee('Call Beta contractor')
        ->call('applyFilters', ['facets.project' => 'Alpha project'])
        ->assertSee('Call Alpha contractor')
        ->assertDontSee('Call Beta contractor')
        ->call('clearFilters')
        ->assertSee('Call Beta contractor');
});
