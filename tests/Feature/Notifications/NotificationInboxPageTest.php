<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
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
