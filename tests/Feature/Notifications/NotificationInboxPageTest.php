<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\Inbox;
use App\Notifications\NotificationPayload;
use Livewire\Livewire;

function pageNotify(User $user, string $subject, string $module = 'tasks', NotificationKind $kind = NotificationKind::Action, NotificationPriority $priority = NotificationPriority::Info): string
{
    $before = UserNotification::query()->pluck('id')->all();

    $user->notify(new AppNotification(new NotificationPayload(
        kind: $kind, module: $module, priority: $priority,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => $subject],
        subjectType: 'task', subjectId: random_int(1, 99999),
    )));

    return UserNotification::query()->whereNotIn('id', $before)->sole()->id;
}

test('guests cannot open the inbox page', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

test('the inbox lists only my notifications', function () {
    [$me, $other] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());
    pageNotify($me, 'Mine');
    pageNotify($other, 'Theirs');

    $this->actingAs($me)->get(route('notifications.index'))->assertOk()->assertSeeText('Mine')->assertDontSeeText('Theirs');
});

test('filters narrow by module kind priority and read state', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    pageNotify($user, 'Task row');
    pageNotify($user, 'Risk row', module: 'risks', priority: NotificationPriority::Critical);
    pageNotify($user, 'Alert row', kind: NotificationKind::Alert);
    UserNotification::query()->find(pageNotify($user, 'Read row'))->markAsRead();

    $page = fn () => Livewire::actingAs($user)->test('pages::notifications.index');

    $page()->set('module', 'risks')->assertSeeText('Risk row')->assertDontSeeText('Task row');
    $page()->set('kind', 'alert')->assertSeeText('Alert row')->assertDontSeeText('Task row');
    $page()->set('priority', 'critical')->assertSeeText('Risk row')->assertDontSeeText('Alert row');
    $page()->set('state', 'read')->assertSeeText('Read row')->assertDontSeeText('Task row');
    $page()->set('state', 'unread')->assertSeeText('Task row')->assertDontSeeText('Read row');
});

test('search matches the stored names, arabic included', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    pageNotify($user, 'توزيع المياه');
    pageNotify($user, 'Hygiene kits');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('search', 'توزيع')
        ->assertSeeText('توزيع المياه')
        ->assertDontSeeText('Hygiene kits');
});

test('selected notifications can be marked read together', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $first = pageNotify($user, 'One');
    $second = pageNotify($user, 'Two');
    $third = pageNotify($user, 'Three');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('selected', [$first, $second])
        ->call('markSelectedRead')
        ->assertSet('selected', []);

    expect(UserNotification::query()->whereNull('read_at')->pluck('id')->all())->toBe([$third]);
});

test('selecting cannot mark another user\'s notification read', function () {
    [$me, $other] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());
    $theirs = pageNotify($other, 'Theirs');

    Livewire::actingAs($me)->test('pages::notifications.index')
        ->set('selected', [$theirs])
        ->call('markSelectedRead');

    expect(UserNotification::query()->find($theirs)->read_at)->toBeNull();
});

test('the list grows by loading more', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    foreach (range(1, 30) as $n) {
        pageNotify($user, "Row {$n}");
    }

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->assertSet('perPage', 25)
        ->assertSeeHtml('data-load-more')
        ->call('loadMore')
        ->assertSet('perPage', 50)
        ->assertDontSeeHtml('data-load-more');
});

test('search matches the words people read, in either language and any case, never the stored keys', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    pageNotify($user, 'Water trucking');

    $page = fn () => Livewire::actingAs($user)->test('pages::notifications.index');

    $page()->set('search', 'created the task')->assertSeeText('Water trucking');
    $page()->set('search', 'CREATED')->assertSeeText('Water trucking');
    $page()->set('search', 'أنشأ')->assertSeeText('Water trucking');

    foreach (['params', 'notifications.events', 'activity_ids'] as $storedKey) {
        $page()->set('search', $storedKey)->assertDontSeeText('Water trucking');
    }
});

test('snoozed notifications can be listed and brought back', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $later = pageNotify($user, 'Later please');
    pageNotify($user, 'Right now');
    app(Inbox::class)->snooze($user, $later, 'tomorrow');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->assertDontSeeText('Later please')
        ->set('state', 'snoozed')
        ->assertSeeText('Later please')
        ->assertDontSeeText('Right now')
        ->assertSeeHtml('data-snoozed-until')
        ->call('unsnooze', $later)
        ->assertDispatched('notification-received')
        ->assertDontSeeText('Later please');

    expect(UserNotification::query()->find($later)->snoozed_until)->toBeNull();
});

test('unread critical notifications are lifted into the attention block', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    pageNotify($user, 'Burning issue', priority: NotificationPriority::Critical);
    pageNotify($user, 'Routine update');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->assertSeeHtml('data-inbox-pinned')
        ->assertSeeInOrder([__('notifications.sections.pinned'), 'Burning issue', 'Routine update'])
        ->set('priority', 'critical')
        ->assertDontSeeHtml('data-inbox-pinned')
        ->assertSeeText('Burning issue');
});

test('reading several at once can be undone, and only my rows are touched', function () {
    [$me, $other] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());
    $first = pageNotify($me, 'One');
    $second = pageNotify($me, 'Two');
    $theirs = pageNotify($other, 'Theirs');

    $page = Livewire::actingAs($me)->test('pages::notifications.index')
        ->call('markIdsRead', [$first, $second, $theirs])
        ->assertDispatched('inbox-undoable', fn (string $event, array $params): bool => $params['action'] === 'read' && collect($params['ids'])->sort()->values()->all() === collect([$first, $second])->sort()->values()->all());

    expect(UserNotification::query()->whereNull('read_at')->pluck('id')->all())->toBe([$theirs]);

    UserNotification::query()->find($theirs)->markAsRead();
    $page->call('undoInbox', 'read', [$first, $second, $theirs]);

    expect(UserNotification::query()->whereNull('read_at')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first, $second])->sort()->values()->all());
});

test('mark all read offers an undo for exactly what it changed', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $unread = pageNotify($user, 'Fresh');
    UserNotification::query()->find(pageNotify($user, 'Old'))->markAsRead();

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->call('markAllRead')
        ->assertDispatched('inbox-undoable', action: 'read', ids: [$unread]);
});

test('the selection can be snoozed together and brought back by undo', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $first = pageNotify($user, 'Snooze first');
    $second = pageNotify($user, 'Snooze second');

    $page = Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('selected', [$first, $second])
        ->call('snoozeSelected', 'tomorrow')
        ->assertSet('selected', [])
        ->assertDispatched('inbox-undoable', action: 'snooze')
        ->assertDontSeeText('Snooze first');

    expect(UserNotification::query()->whereNotNull('snoozed_until')->count())->toBe(2);

    $page->call('undoInbox', 'snooze', [$first, $second])->assertSeeText('Snooze first');

    expect(UserNotification::query()->whereNotNull('snoozed_until')->count())->toBe(0);
});

test('an unknown snooze preset for the selection changes nothing', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $id = pageNotify($user, 'One');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('selected', [$id])
        ->call('snoozeSelected', 'forever')
        ->assertNotDispatched('inbox-undoable');

    expect(UserNotification::query()->find($id)->snoozed_until)->toBeNull();
});

test('the critical shortcut and clear filters reset the view', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    pageNotify($user, 'Burning issue', priority: NotificationPriority::Critical);
    pageNotify($user, 'Routine update', module: 'risks');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('module', 'risks')
        ->call('showCritical')
        ->assertSet('module', '')
        ->assertSet('state', 'unread')
        ->assertSet('priority', 'critical')
        ->assertSeeText('Burning issue')
        ->assertDontSeeText('Routine update')
        ->call('clearFilters')
        ->assertSet('priority', '')
        ->assertSeeText('Routine update');
});

test('the rail counts unread notifications per priority and type', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    pageNotify($user, 'A', priority: NotificationPriority::Critical);
    pageNotify($user, 'B', kind: NotificationKind::Alert);
    UserNotification::query()->find(pageNotify($user, 'C', kind: NotificationKind::Alert))->markAsRead();

    $counts = Livewire::actingAs($user)->test('pages::notifications.index')->instance()->railCounts;

    expect($counts['total'])->toBe(2)
        ->and($counts['priorities'])->toEqualCanonicalizing(['critical' => 1, 'info' => 1])
        ->and($counts['kinds'])->toEqualCanonicalizing(['action' => 1, 'alert' => 1])
        ->and($counts['snoozed'])->toBe(0);
});
