<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Livewire\Livewire;

/**
 * The snapshot of the named Livewire component in a rendered page, as the
 * browser would send it back to the update endpoint.
 */
function renderedLivewireSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot for [{$component}] in the page.");
}

/**
 * Call one method on a component through the Livewire update endpoint, as the
 * Livewire script does (which never asks for a JSON response).
 */
function sendLivewireCall(string $snapshot, string $method, bool $acceptsJson = false): TestResponse
{
    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['method' => $method, 'params' => [], 'metadata' => []]],
    ]]];

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LIVEWIRE' => '1'];

    if ($acceptsJson) {
        $server['HTTP_ACCEPT'] = 'application/json';
    }

    return test()->call('POST', Livewire::getUpdateUri(), server: $server, content: json_encode($payload));
}

/**
 * A user of a role that must use two-factor but with no email to send sign-in
 * codes to, so the authenticator app is their only option.
 */
function requiredRoleWithoutEmail(string $role = 'Super Admin'): User
{
    userWithRole($role);

    return User::factory()->role($role)->create(['email' => '']);
}

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);
});

test('every role that must use two factor goes straight through, covered by emailed codes', function (string $role) {
    $this->actingAs(userWithRole($role))->get(route('dashboard'))->assertOk();
})->with(['Super Admin', 'General Manager', 'Managers', 'Financial']);

test('every role that must use two factor but has no email is sent to the security page until the app is on', function (string $role) {
    $this->actingAs(requiredRoleWithoutEmail($role))
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', __('settings.two_factor_required_notice'));
})->with(['Super Admin', 'General Manager', 'Managers', 'Financial']);

test('a json request from a super admin without email or app is refused with 403', function () {
    $this->actingAs(requiredRoleWithoutEmail())
        ->getJson(route('dashboard'))
        ->assertForbidden()
        ->assertJson(['message' => __('settings.two_factor_required_notice')]);
});

test('a super admin with confirmed two factor goes through', function () {
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create();

    $this->actingAs($superAdmin)->get(route('dashboard'))->assertOk();
});

test('two factor that was set up but never confirmed does not count', function () {
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create(['email' => '', 'two_factor_confirmed_at' => null]);

    $this->actingAs($superAdmin)->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

test('as shipped, two factor is optional for every role, even a super admin with no email', function () {
    config(['access-control.two_factor.required_roles' => (require config_path('access-control.php'))['two_factor']['required_roles']]);

    expect(config('access-control.two_factor.enforced'))->toBeTrue()
        ->and(config('access-control.two_factor.required_roles'))->toBe([]);

    $this->actingAs(requiredRoleWithoutEmail())->get(route('dashboard'))->assertOk();
});

test('a team member without two factor goes through', function () {
    $this->actingAs(userWithRole('Team Member'))->get(route('dashboard'))->assertOk();
});

test('nothing is blocked while enforcement is off', function () {
    config(['access-control.two_factor.enforced' => false]);

    $this->actingAs(requiredRoleWithoutEmail())->get(route('dashboard'))->assertOk();
});

test('nothing is blocked while fortify offers no two factor set-up to enrol with', function () {
    config(['fortify.features' => []]);

    $this->actingAs(requiredRoleWithoutEmail())->get(route('dashboard'))->assertOk();
});

test('the security page stays reachable and says why the person was sent there', function () {
    $this->actingAs(requiredRoleWithoutEmail())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee(__('settings.two_factor_required_heading'))
        ->assertSee(__('settings.enable_2fa'));
});

test('every signed-in app route but the security page requires two factor', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('auth', $route->gatherMiddleware(), true));

    $unguarded = $routes
        ->reject(fn (RoutingRoute $route): bool => in_array(EnsureTwoFactorIsEnabled::class, $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->values()
        ->all();

    expect($routes->count())->toBeGreaterThan(40)
        ->and($unguarded)->toBe(['security.edit'])
        ->and($routes->contains(fn (RoutingRoute $route): bool => in_array('verified', $route->gatherMiddleware(), true)))->toBeFalse();
});

test('a livewire update from a guarded page sends a super admin without email or app to the security page', function () {
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create(['email' => '']);

    $snapshot = renderedLivewireSnapshot(
        $this->actingAs($superAdmin)->get(route('dashboard'))->assertOk()->getContent(),
        'pages::desk.index',
    );

    $superAdmin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    sendLivewireCall($snapshot, '$refresh')->assertRedirect(route('security.edit'));
    sendLivewireCall($snapshot, '$refresh', acceptsJson: true)->assertForbidden();
});

test('the security page two factor set-up still runs for a super admin without email or app', function () {
    $superAdmin = requiredRoleWithoutEmail();

    $snapshot = renderedLivewireSnapshot(
        $this->actingAs($superAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->getContent(),
        'pages::settings.two-factor-setup-modal',
    );

    sendLivewireCall($snapshot, 'startTwoFactorSetup')->assertOk();

    expect($superAdmin->fresh()->two_factor_secret)->not->toBeNull();
});

test('the Ask bar on the security page cannot be driven by a super admin without email or app', function () {
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create(['email' => '']);

    $snapshot = renderedLivewireSnapshot(
        $this->actingAs($superAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->getContent(),
        'desk.ask',
    );

    sendLivewireCall($snapshot, '$refresh')->assertOk();

    $superAdmin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    sendLivewireCall($snapshot, '$refresh')->assertRedirect(route('security.edit'));
});

test('the Ask bar is hidden while a super admin still has to turn on the app', function () {
    $html = $this->actingAs(requiredRoleWithoutEmail())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertDontSee('data-user-id=', false)
        ->getContent();

    expect(fn () => renderedLivewireSnapshot($html, 'desk.ask'))->toThrow(RuntimeException::class);
});

test('a required role with email codes can remove the authenticator app', function () {
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create();

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'));

    expect($superAdmin->fresh()->two_factor_secret)->toBeNull();
});

test('fortify cannot switch off the app when it is a required role\'s only second factor', function () {
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create(['email' => '']);

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasErrors('two_factor');

    expect($superAdmin->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('fortify still switches off two factor for a role that does not require it', function () {
    $member = User::factory()->role('Team Member')->withTwoFactor()->create();

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'));

    expect($member->fresh()->two_factor_secret)->toBeNull();
});

test('an unfinished two factor set-up of a required role can still be cleared', function () {
    $superAdmin = userWithRole('Super Admin');
    $superAdmin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null])->save();

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'));

    expect($superAdmin->fresh()->two_factor_secret)->toBeNull();
});

test('two factor enforcement fails closed on empty or unknown values', function (?string $value, bool $enforced) {
    $previous = getenv('AUTH_TWO_FACTOR_ENFORCED');

    try {
        $value === null ? putenv('AUTH_TWO_FACTOR_ENFORCED') : putenv("AUTH_TWO_FACTOR_ENFORCED={$value}");
        unset($_ENV['AUTH_TWO_FACTOR_ENFORCED'], $_SERVER['AUTH_TWO_FACTOR_ENFORCED']);

        expect((require config_path('access-control.php'))['two_factor']['enforced'])->toBe($enforced);
    } finally {
        $previous === false ? putenv('AUTH_TWO_FACTOR_ENFORCED') : putenv("AUTH_TWO_FACTOR_ENFORCED={$previous}");
    }
})->with([
    'unset' => [null, true],
    'empty' => ['', true],
    'unknown' => ['maybe', true],
    'true' => ['true', true],
    'false' => ['false', false],
    'off' => ['off', false],
    '0' => ['0', false],
]);
