<?php

use App\Actions\Fortify\AuthenticateWithHrAccount;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();
});

test('a passkey confirmation without a redirect falls back to the dashboard route', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertSee("response.redirect || '".route('dashboard', absolute: false)."'", false);
});

test('the hr password confirms the session', function () {
    $user = User::factory()->withHrAccount('hr-secret')->create();

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => 'hr-secret'])
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('a wrong or local password does not confirm the session', function (string $password) {
    $user = User::factory()->withHrAccount('hr-secret')->create();

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => $password])
        ->assertSessionHasErrors();

    expect(session('auth.password_confirmed_at'))->toBeNull();
})->with([
    'wrong' => 'wrong-password',
    'local tracking password' => 'local-secret',
]);

test('the sixth confirmation attempt within a minute is refused even with the right password', function () {
    $this->freezeTime();
    $user = User::factory()->withHrAccount('hr-secret')->create();
    $this->actingAs($user);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.confirm.store'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors(['password' => __('The provided password was incorrect.')]);
    }

    $this->post(route('password.confirm.store'), ['password' => 'hr-secret'])
        ->assertSessionHasErrors(['password' => __('auth.confirm_throttle', ['seconds' => 60])]);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a right password before the limit clears the failed confirmations', function () {
    $user = User::factory()->withHrAccount('hr-secret')->create();
    $this->actingAs($user);

    foreach ([4, 4, 4] as $failedAttempts) {
        foreach (range(1, $failedAttempts) as $attempt) {
            $this->post(route('password.confirm.store'), ['password' => 'wrong-password']);
        }

        $this->post(route('password.confirm.store'), ['password' => 'hr-secret'])
            ->assertSessionHasNoErrors();
    }

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('the confirmation limit is per user', function () {
    $lockedOut = User::factory()->withHrAccount('hr-secret')->create();
    $colleague = User::factory()->withHrAccount('colleague-secret')->create();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($lockedOut)->post(route('password.confirm.store'), ['password' => 'wrong-password']);
    }

    $this->actingAs($colleague)
        ->post(route('password.confirm.store'), ['password' => 'colleague-secret'])
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('ten failed confirmations from any ips lock the user out of confirming for fifteen minutes', function () {
    $this->freezeTime();
    $user = User::factory()->withHrAccount('hr-secret')->create();
    $this->actingAs($user);

    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.5.{$attempt}"])
            ->post(route('password.confirm.store'), ['password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.5.99'])
        ->post(route('password.confirm.store'), ['password' => 'hr-secret'])
        ->assertSessionHasErrors(['password' => __('auth.confirm_throttle', ['seconds' => 900])]);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a wrong confirmation password is reported as a failed attempt without the password', function () {
    Event::fake([Failed::class]);
    $user = User::factory()->withHrAccount('hr-secret')->create();

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password']);

    Event::assertDispatched(Failed::class, fn (Failed $event): bool => $event->guard === 'web'
        && $event->user?->is($user)
        && $event->credentials === ['email' => $user->email]);
});

test('a password login counts as a fresh password confirmation', function () {
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('a failed login confirms nothing', function () {
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    expect(session('auth.password_confirmed_at'))->toBeNull()
        ->and(session('auth.hr_password_checked'))->toBeNull();
});

test('a failing password check keeps the password out of the stack trace', function () {
    $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    $previousMaxLength = ini_set('zend.exception_string_param_max_len', '100');
    $user = User::factory()->withHrAccount('hr-secret')->create();
    Schema::connection('hr')->drop('users');

    try {
        AuthenticateWithHrAccount::confirmsPassword($user, 'typed-hr-secret');
        $this->fail('The HR lookup should have thrown.');
    } catch (QueryException $exception) {
        $stringArguments = collect($exception->getTrace())
            ->flatMap(fn (array $frame): array => $frame['args'] ?? [])
            ->filter(fn (mixed $argument): bool => is_string($argument));

        expect($exception->getTraceAsString())
            ->toContain('confirmsPassword')
            ->toContain($user->email)
            ->not->toContain('typed-hr-secret')
            ->and($stringArguments)->not->toContain('typed-hr-secret');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        ini_set('zend.exception_string_param_max_len', (string) $previousMaxLength);
    }
});
