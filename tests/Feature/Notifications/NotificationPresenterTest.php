<?php

use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ActivityParameters;
use App\Notifications\NotificationPresenter;

test('a status change renders with translated statuses in english and arabic', function () {
    $data = [
        'key' => 'notifications.events.task.status_changed',
        'params' => [
            'actor' => 'Sami Haddad',
            'subject' => 'Distribution plan',
            'from' => ['enum' => 'task_status', 'value' => 'todo'],
            'to' => ['enum' => 'task_status', 'value' => 'in progress'],
        ],
        'count' => 1,
    ];

    $presenter = app(NotificationPresenter::class);

    expect($presenter->sentence($data, 'en'))->toBe('Sami Haddad moved “Distribution plan” from '.__('enums.task_status.todo', [], 'en').' to '.__('enums.task_status.in_progress', [], 'en'))
        ->and($presenter->sentence($data, 'ar'))->toBe('نقل Sami Haddad المهمة «Distribution plan» من '.__('enums.task_status.todo', [], 'ar').' إلى '.__('enums.task_status.in_progress', [], 'ar'));
});

test('localized names follow the reader language', function () {
    $data = [
        'key' => 'notifications.events.task.assigned',
        'params' => ['actor' => 'Sami', 'subject' => 'Plan', 'employee' => ['localized' => ['en' => 'Nour Ali', 'ar' => 'نور علي']]],
        'count' => 1,
    ];

    expect(app(NotificationPresenter::class)->sentence($data, 'ar'))->toContain('نور علي')
        ->and(app(NotificationPresenter::class)->sentence($data, 'en'))->toContain('Nour Ali');
});

test('dates render in the reader language', function () {
    $presenter = app(NotificationPresenter::class);

    expect($presenter->param(['date' => '2026-09-30'], 'en'))->toBe('Sep 30, 2026')
        ->and($presenter->param(['date' => '2026-09-30'], 'ar'))->toContain('2026');
});

test('a merged notification says how many more updates it holds', function () {
    $data = ['key' => 'notifications.events.task.updated', 'params' => ['actor' => 'Sami', 'subject' => 'Plan'], 'count' => 3];

    expect(app(NotificationPresenter::class)->sentence($data, 'en'))->toBe('Sami updated the task “Plan” and 2 more updates');
});

test('activity parameters carry the actor, the label and the changed field typed by its cast', function () {
    $actor = Activity::withoutRecording(fn () => User::factory()->create(['name' => 'Sami Haddad']));
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Plan']));
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.due_date_changed',
        'changes' => ['due_date' => ['2026-09-01 00:00:00', '2026-09-30 00:00:00']],
        'properties' => ['label' => 'Plan', 'notify' => true, 'field' => 'due_date'],
    ]));

    expect(app(ActivityParameters::class)->for($activity))->toBe([
        'actor' => 'Sami Haddad',
        'subject' => 'Plan',
        'from' => ['date' => '2026-09-01'],
        'to' => ['date' => '2026-09-30'],
    ]);
});

test('an enum field is typed by its cast and an empty side reads as none', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks', 'event' => 'task.status_changed',
        'changes' => ['status' => [null, 'done']],
        'properties' => ['label' => 'Plan', 'notify' => true, 'field' => 'status'],
    ]));

    $params = app(ActivityParameters::class)->for($activity);

    expect($params['from'])->toBe(['trans' => 'notifications.none'])
        ->and($params['to'])->toBe(['enum' => 'task_status', 'value' => 'done']);
});

test('a system activity names the system as its actor', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.created', 'changes' => [], 'properties' => ['label' => 'Plan', 'notify' => true],
    ]));

    expect(app(ActivityParameters::class)->for($activity)['actor'])->toBe(['trans' => 'notifications.system']);
});
