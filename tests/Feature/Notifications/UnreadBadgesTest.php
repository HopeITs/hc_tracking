<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\UnreadSubjects;
use Livewire\Livewire;

function notifyAbout(User $user, string $module, string $type, int $id): void
{
    $user->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action, module: $module, priority: NotificationPriority::Info,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'x'],
        subjectType: $type, subjectId: $id,
    )));
}

test('unread subjects are listed per type', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->role('General Manager')->create());
    notifyAbout($user, 'tasks', 'task', 4);
    notifyAbout($user, 'tasks', 'task', 9);
    notifyAbout($user, 'projects', 'project', 4);

    expect(app(UnreadSubjects::class)->ids($user, 'task'))->toBe([4, 9])
        ->and(app(UnreadSubjects::class)->has($user, 'project', 4))->toBeTrue()
        ->and(app(UnreadSubjects::class)->has($user, 'project', 9))->toBeFalse();
});

test('the notifications panel counts the unread notifications', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->role('General Manager')->create());
    notifyAbout($user, 'tasks', 'task', 1);
    notifyAbout($user, 'tasks', 'task', 2);

    Livewire::actingAs($user)->test('desk.panels.inbox', ['size' => 'M'])
        ->assertSeeHtml('<span class="dk-pc dk-num" data-panel-count>2</span>')
        ->call('markAllRead')
        ->assertDontSeeHtml('data-panel-count');
});

test('list rows carry a dot only for records with unread notifications', function (string $route, string $type, Closure $make) {
    $viewer = userWithRole('Super Admin');
    [$flagged, $quiet] = Activity::withoutRecording(fn () => [$make(), $make()]);
    notifyAbout($viewer, 'tasks', $type, $flagged->id);

    $this->actingAs($viewer)->get(route($route))
        ->assertSeeHtml('data-unread-dot="'.$type.'-'.$flagged->id.'"')
        ->assertDontSeeHtml('data-unread-dot="'.$type.'-'.$quiet->id.'"');
})->with([
    'tasks' => ['tasks.index', 'task', fn () => Task::factory()->create()],
    'milestones' => ['milestones.index', 'milestone', fn () => Milestone::factory()->create()],
    'projects' => ['projects.index', 'project', fn () => Project::factory()->create()],
    'reports' => ['reports.index', 'report', fn () => Report::factory()->create()],
    'proposals' => ['proposals.index', 'proposal', fn () => Proposal::factory()->create()],
]);

test('record headers carry the dot only while the record has unread notifications', function (string $route, string $type, Closure $make) {
    $viewer = userWithRole('Super Admin');
    [$flagged, $quiet] = Activity::withoutRecording(fn () => [$make(), $make()]);
    notifyAbout($viewer, 'tasks', $type, $flagged->id);

    $this->actingAs($viewer)->get(route($route, $flagged))->assertSeeHtml('data-unread-dot');
    $this->actingAs($viewer)->get(route($route, $quiet))->assertDontSeeHtml('data-unread-dot');
})->with([
    'task' => ['tasks.show', 'task', fn () => Task::factory()->create()],
    'milestone' => ['milestones.show', 'milestone', fn () => Milestone::factory()->create()],
    'project' => ['projects.show', 'project', fn () => Project::factory()->create()],
    'report' => ['reports.show', 'report', fn () => Report::factory()->create()],
    'proposal' => ['proposals.show', 'proposal', fn () => Proposal::factory()->create()],
    'program' => ['programs.show', 'program', fn () => Program::factory()->create()],
    'funding agreement' => ['funding-agreements.show', 'donor_proposal', fn () => DonorProposal::factory()->create()],
]);
