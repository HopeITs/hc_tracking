<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();
});

test('the hr password confirms the session', function () {
    $user = User::factory()->withHrAccount('hr-secret')->create();

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => 'hr-secret'])
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('a wrong or local password does not confirm the session', function (string $password) {
    $user = User::factory()->withHrAccount('hr-secret')->create(['password' => 'local-secret']);

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => $password])
        ->assertSessionHasErrors();

    expect(session('auth.password_confirmed_at'))->toBeNull();
})->with([
    'wrong' => 'wrong-password',
    'local tracking password' => 'local-secret',
]);
