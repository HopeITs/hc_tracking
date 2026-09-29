<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

use function Livewire\invade;

/*
| Production is HTTPS only: App\Http\Middleware\RedirectToHttps sends plain
| http pages to https and refuses anything else, and AppServiceProvider
| forces every generated URL to https.
*/

/**
 * Re-runs the provider's HTTPS set-up as if the app had started in this
 * environment with this APP_URL.
 */
function configureHttpsAs(string $environment, string $appUrl): void
{
    app()->detectEnvironment(fn (): string => $environment);
    config(['app.url' => $appUrl]);

    invade(new AppServiceProvider(app()))->configureHttps();
}

test('a page asked for over http in production is sent to https before a session starts', function (string $method) {
    app()->detectEnvironment(fn (): string => 'production');

    $this->call($method, 'http://localhost/login?next=%2Fhome')
        ->assertStatus(301)
        ->assertRedirect('https://localhost/login?next=%2Fhome')
        ->assertCookieMissing(config('session.cookie'));
})->with(['GET', 'HEAD']);

test('a form sent over http in production is refused, not redirected', function (string $method) {
    app()->detectEnvironment(fn (): string => 'production');

    $this->call($method, 'http://localhost/login', ['email' => 'someone@example.org'])
        ->assertForbidden()
        ->assertHeaderMissing('Location');
})->with(['POST', 'PATCH']);

test('https requests in production are served', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get(secure_url('login'))->assertOk();
});

test('a proxy that already reports https can never cause a redirect loop', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->withHeader('X-Forwarded-Proto', 'https')
        ->get('http://localhost/login')
        ->assertOk();
});

test('plain http keeps working outside production', function () {
    $this->get('http://localhost/login')->assertOk();
});

test('production generates https links and logs an http APP_URL once', function () {
    Log::shouldReceive('critical')->once()->withArgs(fn (string $message): bool => str_contains($message, 'APP_URL'));

    configureHttpsAs('production', 'http://127.0.0.1:8000/');
    configureHttpsAs('production', 'http://127.0.0.1:8000/');

    expect(url('/login'))->toStartWith('https://');
});

test('an https APP_URL in production is not logged', function () {
    Log::shouldReceive('critical')->never();

    configureHttpsAs('production', 'https://tracking.example.org');

    expect(url('/login'))->toStartWith('https://');
});

test('links keep the request scheme outside production', function () {
    Log::shouldReceive('critical')->never();

    configureHttpsAs('local', 'http://127.0.0.1:8000/');

    expect(url('/login'))->toStartWith('http://');
});
