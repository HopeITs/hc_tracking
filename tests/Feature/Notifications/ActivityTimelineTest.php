<?php

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

test('the timeline shows who did what and each field change', function () {
    $actor = Activity::withoutRecording(fn () => User::factory()->create(['name' => 'Sami']));
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Plan']));

    $this->actingAs($actor);
    $task->update(['status' => TaskStatus::InProgress, 'description' => 'Details']);

    Livewire::actingAs($actor)->test('activity.timeline', ['subjects' => ['task' => [$task->id]]])
        ->assertSeeText('Sami moved “Plan” from '.__('enums.task_status.todo').' to '.__('enums.task_status.in_progress'))
        ->assertSeeText(__('activity.fields.status'))
        ->assertSeeText(__('activity.fields.description'));
});

test('the timeline never shows activity of other records', function () {
    [$mine, $other] = Activity::withoutRecording(fn () => Task::factory()->count(2)->create());
    $mine->update(['title' => 'Mine']);
    $other->update(['title' => 'Not mine']);

    Livewire::actingAs(Activity::withoutRecording(fn () => User::factory()->create()))->test('activity.timeline', ['subjects' => ['task' => [$mine->id]]])
        ->assertSeeText('Mine')
        ->assertDontSeeText('Not mine');
});

test('the timeline with no subjects shows nothing', function () {
    Task::factory()->create(['title' => 'Stray']);

    Livewire::actingAs(Activity::withoutRecording(fn () => User::factory()->create()))->test('activity.timeline', ['subjects' => []])->assertDontSeeText('Stray');
});

test('the timeline loads more on demand', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    foreach (range(1, 25) as $n) {
        $task->update(['title' => "Title {$n}"]);
    }

    Livewire::actingAs(Activity::withoutRecording(fn () => User::factory()->create()))->test('activity.timeline', ['subjects' => ['task' => [$task->id]]])
        ->assertSeeHtml('data-timeline-more')
        ->call('loadMore')
        ->assertSet('limit', 40)
        ->assertDontSeeHtml('data-timeline-more');
});

test('each record page carries its activity', function (string $route, Closure $record) {
    $this->actingAs(userWithRole('Super Admin'))
        ->get(route($route, Activity::withoutRecording($record)))
        ->assertSeeLivewire('activity.timeline');
})->with([
    'task' => ['tasks.show', fn () => Task::factory()->create()],
    'milestone' => ['milestones.show', fn () => Milestone::factory()->create()],
    'report' => ['reports.show', fn () => Report::factory()->create()],
    'proposal' => ['proposals.show', fn () => Proposal::factory()->create()],
    'program' => ['programs.show', fn () => Program::factory()->create()],
    'funding agreement' => ['funding-agreements.show', fn () => DonorProposal::factory()->create()],
]);

test('the project page has an activity tab', function () {
    $project = Activity::withoutRecording(fn () => Project::factory()->create());

    Livewire::actingAs(Activity::withoutRecording(fn () => User::factory()->create()))->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'activity')
        ->assertSeeLivewire('activity.timeline');
});

test('the funding agreement timeline includes its tranches', function () {
    $agreement = Activity::withoutRecording(fn () => DonorProposal::factory()->create());
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);
    $label = Activity::query()->sole()->label();

    $this->actingAs(userWithRole('Super Admin'))
        ->get(route('funding-agreements.show', $agreement))
        ->assertSeeText(__('notifications.events.transfer.scheduled', ['actor' => __('notifications.system'), 'subject' => $label]));
});
