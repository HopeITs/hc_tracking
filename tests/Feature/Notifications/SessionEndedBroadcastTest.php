<?php

use App\Events\SessionEnded;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('a session end is broadcast on the user private channel without a payload', function () {
    $event = new SessionEnded(42);

    expect($event->broadcastOn())->toEqual([new PrivateChannel('App.Models.User.42')])
        ->and($event->broadcastAs())->toBe('session.ended')
        ->and($event->broadcastWith())->toBe([]);
});

test('signing out tells the user open tabs to re-check their session', function () {
    Event::fake([SessionEnded::class]);

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect();

    $this->assertGuest();
    Event::assertDispatched(SessionEnded::class, fn (SessionEnded $event): bool => $event->userId === $user->id);
});

test('the hr sign-out after a password change tells the user open tabs to re-check their session', function () {
    $user = User::factory()->withHrAccount('old-secret')->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'old-secret'])->assertSessionHasNoErrors();

    Event::fake([SessionEnded::class]);

    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->update(['password' => Hash::make('new-secret')]);
    $this->travel(61)->seconds();

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    Event::assertDispatched(SessionEnded::class, fn (SessionEnded $event): bool => $event->userId === $user->id);
});

test('signing out other devices tells the user open tabs to re-check their session', function () {
    Event::fake([SessionEnded::class]);
    config(['session.driver' => 'database']);

    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')->call('signOutOtherDevices');

    Event::assertDispatched(SessionEnded::class, fn (SessionEnded $event): bool => $event->userId === $user->id);
});

test('a failed session-end broadcast is reported and never blocks signing out', function () {
    Exceptions::fake();
    Event::listen(SessionEnded::class, fn () => throw new RuntimeException('websocket server unreachable'));

    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect();

    $this->assertGuest();
    Exceptions::assertReported(RuntimeException::class);
});
