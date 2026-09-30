<?php

use App\Http\DeniedAccessRecorder;
use App\Models\Activity;
use App\Models\UserNotification;
use Illuminate\Http\Request;

/*
| Every refused request (403) is written to the activity log, at most once a
| minute per person and action, without notifying anyone.
*/

test('a refused page visit is recorded against the person refused', function () {
    $manager = userWithRole('Managers');

    $this->actingAs($manager)->get(route('activity.index'))->assertForbidden();

    $activity = Activity::query()->where('event', 'user.access_denied')->sole();

    expect($activity->subject_type)->toBe('user')
        ->and($activity->subject_id)->toBe($manager->id)
        ->and($activity->actor_id)->toBe($manager->id)
        ->and($activity->properties['action'])->toBe('GET /activity')
        ->and($activity->properties['ip'])->toBe('127.0.0.1')
        ->and($activity->shouldNotify())->toBeFalse()
        ->and(UserNotification::query()->count())->toBe(0);
});

test('the same refusal repeated within a minute is recorded once', function () {
    $manager = userWithRole('Managers');

    $this->actingAs($manager)->get(route('activity.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('activity.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('admin.users.index'))->assertForbidden();

    expect(Activity::query()->where('event', 'user.access_denied')->orderBy('id')->pluck('properties')->pluck('action')->all())
        ->toBe(['GET /activity', 'GET /admin/users']);

    $this->travel(2)->minutes();
    $this->actingAs($manager)->get(route('activity.index'))->assertForbidden();

    expect(Activity::query()->where('event', 'user.access_denied')->count())->toBe(3);
});

test('allowed visits and guests are not recorded', function () {
    $this->get(route('activity.index'))->assertRedirect(route('login'));
    $this->actingAs(userWithRole('Super Admin'))->get(route('activity.index'))->assertOk();

    expect(Activity::query()->where('event', 'user.access_denied')->exists())->toBeFalse();
});

test('a refused livewire action is described by its component and method', function () {
    $request = Request::create('/livewire/update', 'POST', [
        'components' => [
            ['snapshot' => json_encode(['memo' => ['name' => 'pages::admin.users']]), 'calls' => [['method' => 'saveRolePermissions']]],
        ],
    ]);

    expect(app(DeniedAccessRecorder::class)->describe($request))->toBe('pages::admin.users::saveRolePermissions');
});

test('the query string never reaches the log', function () {
    $request = Request::create('/admin/users?search=secret-name', 'GET');

    expect(app(DeniedAccessRecorder::class)->describe($request))->toBe('GET /admin/users');
});
