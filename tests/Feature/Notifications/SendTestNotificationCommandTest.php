<?php

use App\Enums\NotificationPriority;
use App\Events\NotificationPushed;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Event;

test('the test command stores and pushes a notification to the user', function () {
    Event::fake([NotificationPushed::class]);
    $user = userWithRole('MEAL');

    $this->artisan('notifications:test', ['email' => $user->email, '--priority' => 'critical', '--delay' => 0])->assertSuccessful();

    expect(UserNotification::query()->forUser($user)->sole()->priority)->toBe(NotificationPriority::Critical)
        ->and(sentencesFor($user))->toBe([__('notifications.test_message')]);

    Event::assertDispatched(NotificationPushed::class, fn (NotificationPushed $event): bool => $event->userId === $user->id
        && $event->payload['priority'] === 'critical');

    $this->actingAs($user)->get(route('notifications.open', UserNotification::query()->value('id')))
        ->assertRedirect(route('notification-settings.edit'));
});

test('the test command refuses an unknown user or priority', function (array $arguments) {
    $user = userWithRole('MEAL');

    $this->artisan('notifications:test', [...['email' => $user->email, '--delay' => 0], ...$arguments])->assertFailed();

    expect(UserNotification::query()->count())->toBe(0);
})->with([
    'unknown email' => [['email' => 'nobody@example.org']],
    'unknown priority' => [['--priority' => 'urgent']],
]);
