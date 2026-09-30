<?php

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Testing\TestResponse;

/**
 * The test suite broadcasts through the null driver, which authorizes every
 * channel without looking. Switch to a signing Reverb driver (no network
 * involved) and register the app's channels on it again.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    app(BroadcastManager::class)->forgetDrivers();

    require base_path('routes/channels.php');
});

function authorizeUserChannel(int $userId): TestResponse
{
    return test()->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Models.User.'.$userId,
    ]);
}

test('a signed-in user may listen on their own notification channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    authorizeUserChannel($user->id)->assertOk()->assertJsonStructure(['auth']);
});

test('a guest is refused before any channel is looked at', function () {
    authorizeUserChannel(User::factory()->create()->id)->assertUnauthorized();
});

test('a user may not listen on someone else notification channel', function () {
    [$user, $other] = User::factory()->count(2)->create();

    $this->actingAs($user);

    authorizeUserChannel($other->id)->assertForbidden();
});

test('channel authorization is rate limited per user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    foreach (range(1, 30) as $attempt) {
        authorizeUserChannel($user->id)->assertOk();
    }

    authorizeUserChannel($user->id)->assertTooManyRequests();
});

test('a user who must still turn on two factor cannot open the live notification stream', function () {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin']]]);

    userWithRole('Super Admin');
    $superAdmin = User::factory()->role('Super Admin')->create(['email' => '']);

    $this->actingAs($superAdmin);

    authorizeUserChannel($superAdmin->id)
        ->assertForbidden()
        ->assertJson(['message' => __('settings.two_factor_required_notice')]);
});
