<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Monolog\Formatter\SyslogFormatter;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

beforeEach(function () {
    config(['logging.channels.auth' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
});

/**
 * The entries written to the auth channel so far.
 *
 * @return list<LogRecord>
 */
function authLogRecords(): array
{
    return Log::channel('auth')->getLogger()->getHandlers()[0]->getRecords();
}

/**
 * The first auth log entry for an event name.
 */
function authLogEntry(string $event): ?LogRecord
{
    return collect(authLogRecords())->first(fn (LogRecord $record): bool => $record->message === $event);
}

test('the auth channel writes a daily auth.log kept for 90 days at info level unless LOG_AUTH_STACK says otherwise', function () {
    $config = require config_path('logging.php');

    // phpunit.xml sets LOG_AUTH_STACK=null, which env() reads as PHP null: it must mean the null channel, not "".
    expect($config['channels']['auth'])->toMatchArray(['driver' => 'stack', 'channels' => ['null']])
        ->and($config['channels']['auth_daily'])->toMatchArray([
            'driver' => 'daily',
            'path' => storage_path('logs/auth.log'),
            'level' => 'info',
            'max_files' => 90,
        ]);
});

test('the optional auth_remote channel sends syslog lines over TLS to LOG_AUTH_REMOTE_URL with short timeouts', function () {
    config(['logging.channels.auth_remote.handler_with.connectionString' => 'tls://logs.example.org:6514']);

    $handler = Log::channel('auth_remote')->getLogger()->getHandlers()[0];

    expect($handler)->toBeInstanceOf(SocketHandler::class)
        ->and($handler->getConnectionString())->toBe('tls://logs.example.org:6514')
        ->and($handler->getFormatter())->toBeInstanceOf(SyslogFormatter::class)
        ->and($handler->getConnectionTimeout())->toBeGreaterThan(0.0)->toBeLessThanOrEqual(5.0)
        ->and($handler->getTimeout())->toBeGreaterThan(0.0)->toBeLessThanOrEqual(5.0)
        ->and($handler->getWritingTimeout())->toBeGreaterThan(0.0)->toBeLessThanOrEqual(5.0);
});

test('an unreachable remote collector does not block a sign-in or the local auth line', function () {
    Exceptions::fake();
    config([
        'logging.channels.auth_local' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.channels.auth' => ['driver' => 'stack', 'channels' => ['auth_local', 'auth_remote'], 'ignore_exceptions' => false],
        'logging.channels.auth_remote.handler_with.connectionString' => 'tls://127.0.0.1:9',
    ]);
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(authLogEntry('login')?->context)->toMatchArray(['user_id' => $user->id]);
    Exceptions::assertReported(UnexpectedValueException::class);
});

test('a sign-in is logged with the user, ip, user agent and guard', function () {
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $user = User::factory()->withHrAccount()->create();

    $this->withHeaders(['User-Agent' => $userAgent])
        ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(authLogEntry('login'))->not->toBeNull()
        ->and(authLogEntry('login')->context)->toBe([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => '10.1.2.3',
            'user_agent' => $userAgent,
            'guard' => 'web',
        ]);
});

test('a failed sign-in logs the typed email and never the password', function () {
    $this->post(route('login.store'), ['email' => 'nobody@example.org', 'password' => 'Sup3r-Secret-Guess'])
        ->assertSessionHasErrors();

    expect(authLogEntry('login_failed')?->context)->toMatchArray(['user_id' => null, 'email' => 'nobody@example.org'])
        ->and(json_encode(array_map(fn (LogRecord $record): array => $record->toArray(), authLogRecords())))
        ->not->toContain('Sup3r-Secret-Guess');
});

test('a lockout is logged with the typed email and never the password', function () {
    $request = Request::create('/login', 'POST', ['email' => 'nobody@example.org', 'password' => 'Sup3r-Secret-Guess']);

    event(new Lockout($request));

    expect(authLogEntry('lockout')?->context)->toMatchArray(['user_id' => null, 'email' => 'nobody@example.org', 'guard' => 'web'])
        ->and(json_encode(authLogEntry('lockout')->toArray()))->not->toContain('Sup3r-Secret-Guess');
});

test('a lockout of a signed-in request is logged against that user', function () {
    $user = User::factory()->create();
    $request = Request::create('/user/confirm-password', 'POST', ['password' => 'Sup3r-Secret-Guess']);
    $request->setUserResolver(fn (): User => $user);

    event(new Lockout($request));

    expect(authLogEntry('lockout')?->context)->toMatchArray(['user_id' => $user->id, 'email' => $user->email])
        ->and(json_encode(authLogEntry('lockout')->toArray()))->not->toContain('Sup3r-Secret-Guess');
});

test('a sign-out is logged', function () {
    $user = User::factory()->create();

    Auth::login($user);
    Auth::logout();

    expect(authLogEntry('logout')?->context)->toMatchArray(['user_id' => $user->id, 'email' => $user->email]);
});

test('turning two-factor authentication off is logged', function () {
    $user = User::factory()->create();

    event(new TwoFactorAuthenticationDisabled($user));

    expect(authLogEntry('two_factor_disabled')?->context)->toMatchArray(['user_id' => $user->id, 'email' => $user->email, 'guard' => 'web']);
});

test('using a recovery code is logged without the code', function () {
    $user = User::factory()->create();

    event(new RecoveryCodeReplaced($user, 'ABCDE-FGHIJ'));

    expect(authLogEntry('recovery_code_used')?->context)->toMatchArray(['user_id' => $user->id])
        ->and(json_encode(authLogEntry('recovery_code_used')->toArray()))->not->toContain('ABCDE-FGHIJ');
});
